<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Persona;
use App\Models\Rol;
use App\Models\User;
use App\Support\Rut;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class SsoController extends Controller
{
    /**
     * Roles que el SSO puede otorgar.
     *
     * Lista blanca aunque el token venga firmado: si UTAmed alguna vez manda un rol
     * equivocado por un error suyo, acá no se convierte en un permiso de SGEQ.
     * SUPER_USUARIO no está a propósito —se administra en SGEQ, el SSO no lo toca—.
     */
    protected const ROLES_PERMITIDOS = ['ADMIN', 'ALUMNO'];

    /** Del más al menos privilegiado, para saber cuál mostrarle al frontend. */
    protected const PRIORIDAD_ROLES = ['ADMIN', 'SUPER_USUARIO', 'ALUMNO'];

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
        ]);

        try {
            return response()->json($this->authenticateFromToken($request->string('token')->toString()), 200);
        } catch (Throwable $e) {
            Log::warning('[SSO] Autenticación rechazada', [
                'message' => $e->getMessage(),
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'message' => 'Token SSO inválido',
            ], 401);
        }
    }

    public function consume(Request $request): RedirectResponse
    {
        $token = (string) $request->query('token', '');

        if (trim($token) === '') {
            return $this->redirectToFrontendError('token_missing');
        }

        try {
            $authData = $this->authenticateFromToken($token);
            $ticket = Str::random(64);

            Cache::put('sso:ticket:' . $ticket, $authData, now()->addMinute());

            return $this->redirectToFrontend([
                'ticket' => $ticket,
            ]);
        } catch (Throwable $e) {
            Log::warning('[SSO] Consumo web rechazado', [
                'message' => $e->getMessage(),
                'ip' => $request->ip(),
            ]);

            return $this->redirectToFrontendError('sso_invalid');
        }
    }

    public function exchange(Request $request): JsonResponse
    {
        $request->validate([
            'ticket' => ['required', 'string'],
        ]);

        $ticket = $request->string('ticket')->toString();
        $cacheKey = 'sso:ticket:' . $ticket;
        $authData = Cache::pull($cacheKey);

        if (!is_array($authData) || !isset($authData['token'], $authData['user'])) {
            return response()->json(['message' => 'Ticket SSO inválido o expirado'], 401);
        }

        return response()->json($authData, 200);
    }

    /**
     * @return array<string, mixed>
     */
    protected function authenticateFromToken(string $jwt): array
    {
        $payload = $this->decodeToken($jwt);
        $this->validateClaims($payload);

        $email = $this->extractEmail($payload);
        // validateClaims ya rechazó el token si el rol falta o no está permitido.
        $rolPedido = (string) $this->extractRol($payload);

        $persona = $this->resolvePersona($payload);
        $user = $this->resolveUser($persona, $email, $rolPedido);

        if (($user->estado ?? 'ACTIVO') !== 'ACTIVO' || ($persona->estado ?? 'ACTIVO') !== 'ACTIVO') {
            throw new \RuntimeException('Usuario deshabilitado');
        }

        if ((bool) ($user->bloqueado ?? false) === true) {
            throw new \RuntimeException('Usuario bloqueado');
        }

        $user->tokens()->delete();
        $token = $user->createToken('sso-token')->plainTextToken;
        $rol = $this->rolPrincipal($user);

        return [
            'token' => $token,
            'user' => [
                'id' => $user->idUser,
                'nombre' => trim(implode(' ', array_filter([
                    $persona->Nombre ?? '',
                    $persona->apellido1 ?? '',
                    $persona->apellido2 ?? '',
                ]))),
                'email' => $user->Email ?? '',
                'rol' => [
                    'nombre' => $rol->Nombre ?? null,
                    'descripcion' => $rol->Descricion ?? null,
                ],
            ],
        ];
    }

    /**
     * @param array<string, string> $params
     */
    protected function redirectToFrontend(array $params): RedirectResponse
    {
        $callbackUrl = (string) config('services.utamed_sso.frontend_callback_url');

        if ($callbackUrl === '') {
            throw new \RuntimeException('Callback frontend SSO no configurado');
        }

        $separator = str_contains($callbackUrl, '?') ? '&' : '?';

        return redirect()->away($callbackUrl . $separator . http_build_query($params));
    }

    protected function redirectToFrontendError(string $error): RedirectResponse
    {
        return $this->redirectToFrontend([
            'error' => $error,
        ]);
    }

    /**
     * @return object
     */
    protected function decodeToken(string $jwt): object
    {
        $publicKeyPath = config('services.utamed_sso.public_key_path');

        if (!$publicKeyPath || !is_file($publicKeyPath)) {
            throw new \RuntimeException('Clave pública SSO no configurada');
        }

        $publicKey = file_get_contents($publicKeyPath);

        if ($publicKey === false || trim($publicKey) === '') {
            throw new \RuntimeException('No se pudo leer la clave pública SSO');
        }

        return JWT::decode($jwt, new Key($publicKey, 'RS256'));
    }

    protected function validateClaims(object $payload): void
    {
        $issuer = config('services.utamed_sso.issuer');
        $audience = config('services.utamed_sso.audience');

        if (($payload->iss ?? null) !== $issuer) {
            throw new \RuntimeException('Issuer inválido');
        }

        $aud = $payload->aud ?? null;
        $audiences = is_array($aud) ? $aud : [$aud];
        if (!in_array($audience, array_filter($audiences), true)) {
            throw new \RuntimeException('Audience inválido');
        }

        $exp = isset($payload->exp) ? (int) $payload->exp : 0;
        if ($exp <= now()->timestamp) {
            throw new \RuntimeException('Token expirado');
        }

        $jti = $payload->jti ?? null;
        if (!is_string($jti) || trim($jti) === '') {
            throw new \RuntimeException('JTI ausente');
        }

        $cacheKey = 'sso:jti:' . $jti;
        $ttl = max(60, $exp - now()->timestamp);
        if (!Cache::add($cacheKey, true, now()->addSeconds($ttl))) {
            throw new \RuntimeException('Token reutilizado');
        }

        $rut = $this->extractRut($payload);
        if ($rut === null) {
            throw new \RuntimeException('RUT ausente');
        }

        $email = $this->extractEmail($payload);
        if ($email === null) {
            throw new \RuntimeException('Email ausente');
        }

        if ($this->extractRol($payload) === null) {
            throw new \RuntimeException('Rol ausente o no permitido');
        }
    }

    protected function resolvePersona(object $payload): Persona
    {
        $rut = $this->extractRut($payload);
        $email = $this->extractEmail($payload);
        $names = $this->extractNameParts($payload);

        // Ambos lados escriben el RUT igual (ver App\Support\Rut y la migración
        // que normalizó la columna), así que la comparación directa alcanza.
        $persona = Persona::where('Rut', $rut)->first();

        if (!$persona) {
            // Red por si la migración de normalización todavía no corrió en este
            // entorno: sin esto, cada persona con el RUT escrito con puntos entraría
            // de nuevo como una persona distinta.
            $persona = Persona::whereRaw("REPLACE(REPLACE(`Rut`, '.', ''), '-', '') = ?", [Rut::soloDigitos($rut)])->first();
        }

        if (!$persona && $email) {
            // users.Email es la fuente de verdad para identidad por correo; persona.Email es solo informativo.
            $existingUser = User::where('Email', $email)->with('persona')->first();
            $persona = $existingUser->persona ?? null;
        }

        $carrera = $this->extractCarrera($payload);

        if ($persona) {
            $persona->fill(array_filter([
                'Nombre' => $persona->Nombre ?: $names['Nombre'],
                'apellido1' => $persona->apellido1 ?: $names['apellido1'],
                'apellido2' => $persona->apellido2 ?: $names['apellido2'],
                'Email' => $persona->Email ?: $email,
                // El RUT se reescribe siempre: si estaba con puntos, ésta es la
                // oportunidad de dejarlo canónico (el mutador lo normaliza).
                'Rut' => $rut,
                // La carrera sí se actualiza: viene de UTAmed, que es donde se
                // matricula la gente, y acá es sólo un dato de referencia.
                'carrera' => $carrera,
            ], fn ($value) => $value !== null && $value !== ''));

            if ($persona->isDirty()) {
                $persona->save();
            }

            return $persona;
        }

        return Persona::create([
            'Nombre' => $names['Nombre'] ?? 'Usuario',
            'apellido1' => $names['apellido1'] ?? 'SSO',
            'apellido2' => $names['apellido2'],
            'Rut' => $rut,
            'Email' => $email,
            'carrera' => $carrera,
            'estado' => 'ACTIVO',
        ]);
    }

    protected function resolveUser(Persona $persona, ?string $email, string $rol): User
    {
        $user = User::where('idPersona', $persona->idPersona)->with('roles')->first();

        if (!$user) {
            $user = User::create([
                'idPersona' => $persona->idPersona,
                // Contraseña aleatoria que nadie conoce: esta cuenta se abre por SSO.
                'Contrasena' => Hash::make(Str::random(40)),
                'estadoSancion' => 'ACTIVO',
                'estado' => 'ACTIVO',
                'bloqueado' => false,
                'Email' => $email ?? $persona->Email,
            ]);
        }

        $this->asignarRol($user, $rol);

        return $user->load('roles');
    }

    /**
     * Añade el rol que trae el token sin quitarle a la persona los que ya tenga.
     *
     * `syncWithoutDetaching` y no `sync`, a propósito: SUPER_USUARIO se administra
     * dentro de SGEQ y el SSO no sabe que existe. Con `sync`, el encargado que
     * entrara por UTAmed saldría convertido en ALUMNO.
     */
    protected function asignarRol(User $user, string $rol): void
    {
        $idRol = Rol::where('Nombre', $rol)->value('idRol');

        if ($idRol === null) {
            throw new \RuntimeException("El rol {$rol} no existe en SGEQ; corre el RolesTableSeeder.");
        }

        $user->roles()->syncWithoutDetaching([$idRol]);
    }

    /**
     * Rol con el que el frontend decide qué ve esta persona.
     *
     * Se elige el de más autoridad de los que tiene, no el primero que devuelva la
     * base: alguien puede ser ALUMNO por el SSO y ADMIN por asignación local, y
     * entrar por UTAmed no debería esconderle el panel que sí le corresponde.
     */
    protected function rolPrincipal(User $user): ?Rol
    {
        $roles = $user->roles()->get();

        foreach (self::PRIORIDAD_ROLES as $nombre) {
            $encontrado = $roles->firstWhere('Nombre', $nombre);

            if ($encontrado) {
                return $encontrado;
            }
        }

        return $roles->first();
    }

    protected function extractRut(object $payload): ?string
    {
        $rut = $payload->rut ?? $payload->sub ?? null;

        if (!is_string($rut) || trim($rut) === '') {
            return null;
        }

        // UTAmed ya lo manda canónico, pero normalizar acá cierra el círculo: es la
        // misma escritura con la que quedó la columna y con la que se busca.
        return Rut::normalizar($rut);
    }

    /**
     * Rol pedido por el token, si es uno de los que el SSO puede otorgar.
     */
    protected function extractRol(object $payload): ?string
    {
        $rol = $payload->rol ?? null;

        if (!is_string($rol)) {
            return null;
        }

        $rol = strtoupper(trim($rol));

        return in_array($rol, self::ROLES_PERMITIDOS, true) ? $rol : null;
    }

    /** Carrera informada por UTAmed; en SGEQ es sólo un dato de referencia. */
    protected function extractCarrera(object $payload): ?string
    {
        $carrera = $payload->carrera ?? null;

        return is_string($carrera) && trim($carrera) !== '' ? trim($carrera) : null;
    }

    protected function extractEmail(object $payload): ?string
    {
        $email = $payload->email ?? null;

        return is_string($email) && trim($email) !== '' ? trim(Str::lower($email)) : null;
    }

    /**
     * @return array{Nombre:string,apellido1:string,apellido2:?string}
     */
    protected function extractNameParts(object $payload): array
    {
        $nombre = $payload->nombre ?? null;
        $nombre1 = $payload->nombre1 ?? null;
        $apellido1 = $payload->apellido1 ?? null;
        $apellido2 = $payload->apellido2 ?? null;

        if (is_string($nombre1) && trim($nombre1) !== '') {
            return [
                'Nombre' => trim($nombre1),
                'apellido1' => is_string($apellido1) && trim($apellido1) !== '' ? trim($apellido1) : 'SSO',
                'apellido2' => is_string($apellido2) && trim($apellido2) !== '' ? trim($apellido2) : null,
            ];
        }

        $fullName = is_string($nombre) && trim($nombre) !== '' ? preg_split('/\s+/', trim($nombre)) ?: [] : [];
        $firstName = count($fullName) > 0 ? array_shift($fullName) : 'Usuario';
        $lastName1 = count($fullName) > 0 ? array_shift($fullName) : 'SSO';
        $lastName2 = count($fullName) > 0 ? implode(' ', $fullName) : null;

        return [
            'Nombre' => $firstName,
            'apellido1' => $lastName1,
            'apellido2' => $lastName2,
        ];
    }
}