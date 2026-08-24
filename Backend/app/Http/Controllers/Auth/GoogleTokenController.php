<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Google\Auth\AccessToken;
use Google\Auth\Cache\FileSystemCacheItemPool;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Models\Persona;
use App\Models\User;
use App\Models\Rol;

class GoogleTokenController extends Controller
{
    public function login(Request $request)
    {
        try {

            $request->validate([
                'token' => 'required|string',
            ]);

            // =========================
            // VERIFICACIÓN CRIPTOGRÁFICA DEL ID TOKEN
            // =========================
            // Se verifica la firma RS256 contra las claves públicas de Google (JWKS)
            // ADEMÁS de las claims aud/iss/exp. Validar solo las claims es inseguro:
            // el payload lo controla quien envía el token, y el client_id es público
            // (viaja en el frontend), así que cualquiera podría fabricar uno.
            $clientId = config('services.google.client_id');

            if (empty($clientId)) {
                Log::error('Google login mal configurado: services.google.client_id vacío');
                return response()->json(['message' => 'Autenticación con Google no disponible'], 503);
            }

            $payload = $this->verificarIdToken($request->input('token'), $clientId);

            if (!$payload) {
                Log::warning('Google login: ID token inválido (firma, aud, iss o exp)');
                return response()->json(['message' => 'Token de Google inválido'], 401);
            }

            if (($payload['email_verified'] ?? false) !== true) {
                Log::warning('Google login: email no verificado');
                return response()->json(['message' => 'Email no verificado'], 401);
            }

            $email = $payload['email'] ?? null;
            $name  = $payload['name'] ?? 'Usuario Google';

            if (!$email) {
                Log::warning('Google login: token sin claim email');
                return response()->json(['message' => 'Token de Google inválido'], 401);
            }

            // =========================
            // RESOLUCIÓN DE USUARIO (users.Email es la fuente de verdad para login)
            // =========================
            $user = User::where('Email', $email)->with('persona')->first();

            if ($user) {
                $persona = $user->persona;
            } else {
                // Fallback: puede existir una Persona (creada por otro flujo) sin User asociado todavía.
                $persona = Persona::where('Email', $email)->first();

                if (!$persona) {
                    // Generar un Rut válido/no nulo para cumplir la restricción de la BD
                    // Usamos el "sub" de Google como identificador estable, y si no existe,
                    // generamos un UUID. Se trunca a 20 caracteres por seguridad.
                    $rutGenerado = substr((string)($payload['sub'] ?? Str::uuid()), 0, 20);

                    $persona = Persona::create([
                        'Nombre'    => $name,
                        'apellido1' => '',
                        'apellido2' => null,
                        'Rut'       => $rutGenerado,
                        'Email'     => $email,
                    ]);

                    Log::info('Google login: Persona creada', ['idPersona' => $persona->idPersona]);
                }

                $user = User::create([
                    'idPersona'     => $persona->idPersona,
                    'Contrasena'    => bcrypt(Str::random(32)),
                    'estadoSancion' => 'ACTIVO',
                    'Email'         => $email,
                ]);

                Log::info('Google login: User creado', ['idUser' => $user->idUser]);

                $rol = Rol::where('Nombre', 'ALUMNO')->first();
                if ($rol) {
                    $user->roles()->attach($rol->IdRol);
                }
            }

            // =========================
            // EMISIÓN DE TOKEN SANCTUM
            // =========================
            $token = $user->createToken('auth-token')->plainTextToken;

            return response()->json([
                'token' => $token,
                'user' => [
                    'id' => $user->idUser,
                    'nombre' => $persona->Nombre,
                    'email' => $user->Email,
                    'rol' => [
                        'nombre' => $user->roles->first()->Nombre ?? null,
                    ],
                ],
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Error general Google Login', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // No se devuelve $e->getMessage() al cliente: filtraría detalles internos
            // (rutas, SQL, configuración) en un endpoint público sin autenticar.
            return response()->json([
                'message' => 'Error interno Google Login'
            ], 500);
        }
    }

    /**
     * Verifica un ID token de Google: firma RS256 contra el JWKS de Google,
     * más las claims aud/iss/exp.
     *
     * Los certificados se cachean en disco entre peticiones para no golpear el
     * endpoint de Google en cada login.
     *
     * @return array<string,mixed>|null Payload verificado, o null si el token no es válido.
     */
    private function verificarIdToken(string $token, string $clientId): ?array
    {
        try {
            $cache = new FileSystemCacheItemPool(
                storage_path('framework/cache/google-certs')
            );

            // Sin la opción 'issuer', google/auth acepta tanto "accounts.google.com"
            // como "https://accounts.google.com", que son las dos formas que emite Google.
            $payload = (new AccessToken(null, $cache))->verify($token, [
                'audience' => $clientId,
            ]);

            return $payload === false ? null : $payload;

        } catch (\Throwable $e) {
            // Falla cerrado: cualquier problema al verificar (certs inalcanzables,
            // token corrupto) se trata como token inválido.
            Log::warning('Google login: fallo al verificar ID token', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
