<?php

namespace Tests\Feature\Auth;

use App\Models\Asignatura;
use App\Models\Persona;
use App\Models\Rol;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SsoLoginTest extends TestCase
{
    use RefreshDatabase;

    private const TEST_PRIVATE_KEY = <<<'KEY'
-----BEGIN PRIVATE KEY-----
MIIEvAIBADANBgkqhkiG9w0BAQEFAASCBKYwggSiAgEAAoIBAQCm8ODv8sJAdw35
cDLVjWmdX5LtHbuCUY67NQi3/ullnKbqCbiG42Cqaw5Wij0jaApOPw6w+dJ4tMAk
Fce8XzQy6GhMlvNiXCtZgAL1XbO1uNAqxMGVFVUkWriqxjQZEBXNVMhbBCh0aXIE
qj2EiGf02LNsfJmzAqJ65d2PUJ4u5SmkFtnhFOoT08x/jshwPxb2QC+Dw+8Mzy/o
3LPUEjnDc96uQ069tFLD6qyZE7lstbzL8VYv7u3qfF44g0p+Zt9dtUqdwZiNGl0R
Za0FqI+xtUPaARECNG0PtM4ONJ+s5FwIY4Jc6MEns9tyKflNJsxtZYdq3Jmbb5B9
tJe/S3+1AgMBAAECggEAJ9+yAm376tTk8BbV9X8E4nNxDxTCdEKRsnJc5kHLMpuD
nLP8sK8/qATRFGeJadsQVxclpazOEkmt+RCAuCQPPjeXre+CK5SzP/nc+wlKAtYl
VonPaRIC15+ZqySTTgczBevBvUeVeBS1iJq2/eBs4CUgWgG63nB7KZqc3H3XgoCT
gRS9AfQY3HoyOzgO5blQazgijp+7g4y/FgcDwP0+YEguuT7jmew/G+PSFNVw3xJ3
N5YR919Ey0KDuLqjZ08lb1G8vhDhCjqwHVQuLqRzKsn1s5nY8aW06046bdN+RGAS
FJ1noIn1HJA2mjHcWw37YcLxl69iXmaV9YQF26f+4QKBgQDpaef/Bova4PbMSIki
nXVKdkXAYj/gaqa3SUs/gmeIA+VfA5GEapC5cbawVNajqajIo7Dzd2mUmp8IY8Mx
vEAGPuprCgsRE8ziCc6xW16PeDQplJWqZ+WlHv4Q9cf45QzVob0JvGaqmrCW0Jql
KcX39ni9+EJsnKyAVPIoQ68n0QKBgQC3GFFYKhJ6creahq2xcDwODhhZe2CVLLCk
V3ozyyddR0/HxgMlixCLuGrzxrY5pItoWk6Sb11mrliD43rHA1Bqm4f1kYLp6Gam
9wB3n+CiUsDJMcLSPvm0nh1bc7LEui4+Y/oDNHibvz1B3E3+MLOM5QxxWpQO9MED
Ucz0CeT2pQKBgG1UPud0QIPIRbFP9HPzPuIe3fML0hGiwu4s9YMM6MOL158Wg817
QMir279iLZtBN56rFZIkh56kggMi/2XHYFHMnG6AqMhZ9uiVYWwveO5Ihl5Hi4bi
3WznRGfbR8xsNQPHm0z5Izmb9UTe9uCP3XuUxd9tbmeDR0VhBIAZm+xRAoGAKxDe
OUWUGRcYlpEtE7pZddjc41dAzXW4ir9EsCANv0QBwPSTUuZV2vdiuLo5rG9GlyH+
rzTgnEFP8p20CFGAPcMdhKZYS5ptYsJgasLBPI0IaYp5z6geZdx2/UbGer1sGSK8
8LoL0F54EyC1e5+K7A4IEjWC882gBJ7d/VzCbT0CgYBdHslj6ju/5D3/5bC8SA0b
8A0XJy0TXNdPCva03qEaf4rzbAuDIU5E5xo5TJ8KsW9B/wPbsjG1kKIcpPAkWN+P
6+Je+9CFeW+XMaFlBopT1igq3am4cLbpU1/raYzofHT0tLaN5+Es1NTmUV5qjAlC
411E58C6RtQhE5AIdo+eFA==
-----END PRIVATE KEY-----
KEY;

    private const TEST_PUBLIC_KEY = <<<'KEY'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEApvDg7/LCQHcN+XAy1Y1p
nV+S7R27glGOuzUIt/7pZZym6gm4huNgqmsOVoo9I2gKTj8OsPnSeLTAJBXHvF80
MuhoTJbzYlwrWYAC9V2ztbjQKsTBlRVVJFq4qsY0GRAVzVTIWwQodGlyBKo9hIhn
9NizbHyZswKieuXdj1CeLuUppBbZ4RTqE9PMf47IcD8W9kAvg8PvDM8v6Nyz1BI5
w3PerkNOvbRSw+qsmRO5bLW8y/FWL+7t6nxeOINKfmbfXbVKncGYjRpdEWWtBaiP
sbVD2gERAjRtD7TODjSfrORcCGOCXOjBJ7Pbcin5TSbMbWWHatyZm2+QfbSXv0t/
tQIDAQAB
-----END PUBLIC KEY-----
KEY;

    private string $privateKey;

    private string $publicKeyPath;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->generateKeys();

        config()->set('services.utamed_sso.issuer', 'utamed');
        config()->set('services.utamed_sso.audience', 'sgeq');
        config()->set('services.utamed_sso.public_key_path', $this->publicKeyPath);

        // El rol ya no sale de la configuracion: lo trae el token. SUPER_USUARIO
        // existe para comprobar que el SSO no se lo quita a nadie.
        foreach (['ALUMNO', 'ADMIN', 'SUPER_USUARIO'] as $nombre) {
            Rol::create(['Nombre' => $nombre]);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->publicKeyPath) && is_file($this->publicKeyPath)) {
            @unlink($this->publicKeyPath);
        }

        parent::tearDown();
    }

    public function test_it_accepts_a_valid_utamed_token_and_returns_sanctum_token(): void
    {
        $jwt = $this->makeJwt([
            'sub' => '12345678-9',
            'rut' => '12345678-9',
            'email' => 'alumno@utamed.cl',
            'nombre' => 'Juan Perez Soto',
            'iss' => 'utamed',
            'aud' => 'sgeq',
            'exp' => now()->addMinute()->timestamp,
            'jti' => 'jwt-valid-1',
            'rol' => 'ALUMNO',
        ]);

        $response = $this->postJson('/api/auth/sso', [
            'token' => $jwt,
        ]);

        $response
            ->assertOk()
            ->assertJsonStructure([
                'token',
                'user' => ['id', 'nombre', 'email', 'rol' => ['nombre']],
            ])
            ->assertJsonPath('user.email', 'alumno@utamed.cl')
            ->assertJsonPath('user.rol.nombre', 'ALUMNO');

        $this->assertDatabaseHas('persona', [
            'Rut' => '12345678-9',
            'Email' => 'alumno@utamed.cl',
        ]);

        $persona = Persona::where('Rut', '12345678-9')->firstOrFail();

        $this->assertDatabaseHas('users', [
            'idPersona' => $persona->idPersona,
            'Email' => 'alumno@utamed.cl',
        ]);
    }

    public function test_it_rejects_a_replayed_token_by_jti(): void
    {
        $jwt = $this->makeJwt([
            'sub' => '11111111-1',
            'rut' => '11111111-1',
            'email' => 'replay@utamed.cl',
            'nombre' => 'Replay Token',
            'iss' => 'utamed',
            'aud' => 'sgeq',
            'exp' => now()->addMinute()->timestamp,
            'jti' => 'jwt-replayed-1',
            'rol' => 'ALUMNO',
        ]);

        $this->postJson('/api/auth/sso', ['token' => $jwt])->assertOk();

        $this->postJson('/api/auth/sso', ['token' => $jwt])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Token SSO inválido');
    }

    public function test_it_matches_an_existing_persona_whose_rut_has_dots(): void
    {
        // Dato heredado: se inserta por debajo de Eloquent a proposito, porque el
        // mutador de Persona ya normalizaria el RUT al escribirlo.
        $idPersona = DB::table('persona')->insertGetId([
            'Nombre' => 'Ana',
            'apellido1' => 'Perez',
            'Rut' => '11.111.111-1',
            'Email' => 'ana.antigua@utamed.cl',
            'estado' => 'ACTIVO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $jwt = $this->makeJwt($this->claims([
            'sub' => '11111111-1',
            'rut' => '11111111-1',
            'email' => 'ana.antigua@utamed.cl',
            'jti' => 'jwt-rut-con-puntos',
            'rol' => 'ALUMNO',
        ]));

        $this->postJson('/api/auth/sso', ['token' => $jwt])->assertOk();

        // Lo que se esta comprobando es que NO aparecio una segunda Ana.
        $this->assertSame(1, Persona::count());
        $this->assertSame('11111111-1', Persona::find($idPersona)->Rut);
    }

    public function test_it_grants_the_role_the_token_asks_for(): void
    {
        $jwt = $this->makeJwt($this->claims([
            'sub' => '22222222-2',
            'rut' => '22222222-2',
            'email' => 'jefa@utamed.cl',
            'jti' => 'jwt-admin',
            'rol' => 'ADMIN',
        ]));

        $this->postJson('/api/auth/sso', ['token' => $jwt])
            ->assertOk()
            ->assertJsonPath('user.rol.nombre', 'ADMIN');

        $user = User::where('Email', 'jefa@utamed.cl')->firstOrFail();
        $this->assertTrue($user->hasRole('ADMIN'));
    }

    public function test_it_does_not_take_away_super_usuario(): void
    {
        // SUPER_USUARIO se administra dentro de SGEQ; entrar por UTAmed como
        // ALUMNO no puede degradar al encargado.
        $persona = Persona::create([
            'Nombre' => 'Encargado',
            'apellido1' => 'Equipos',
            'Rut' => '33333333-3',
            'Email' => 'encargado@utamed.cl',
            'estado' => 'ACTIVO',
        ]);

        $user = User::create([
            'idPersona' => $persona->idPersona,
            'Contrasena' => bcrypt('lo-que-sea'),
            'estadoSancion' => 'ACTIVO',
            'Email' => 'encargado@utamed.cl',
        ]);
        $user->roles()->attach(Rol::where('Nombre', 'SUPER_USUARIO')->value('idRol'));

        $jwt = $this->makeJwt($this->claims([
            'sub' => '33333333-3',
            'rut' => '33333333-3',
            'email' => 'encargado@utamed.cl',
            'jti' => 'jwt-super',
            'rol' => 'ALUMNO',
        ]));

        $this->postJson('/api/auth/sso', ['token' => $jwt])
            ->assertOk()
            // El frontend recibe el rol de mas autoridad, no el que trajo el token.
            ->assertJsonPath('user.rol.nombre', 'SUPER_USUARIO');

        $user->refresh();
        $this->assertTrue($user->hasRole('SUPER_USUARIO'));
        $this->assertTrue($user->hasRole('ALUMNO'));
    }

    public function test_it_rejects_a_role_outside_the_whitelist(): void
    {
        // Firma valida, rol que el SSO no puede otorgar.
        $jwt = $this->makeJwt($this->claims([
            'sub' => '44444444-4',
            'rut' => '44444444-4',
            'email' => 'colado@utamed.cl',
            'jti' => 'jwt-rol-prohibido',
            'rol' => 'SUPER_USUARIO',
        ]));

        $this->postJson('/api/auth/sso', ['token' => $jwt])->assertStatus(401);

        $this->assertDatabaseMissing('persona', ['Rut' => '44444444-4']);
    }

    public function test_it_rejects_a_token_without_a_role(): void
    {
        $claims = $this->claims([
            'sub' => '55555555-5',
            'rut' => '55555555-5',
            'email' => 'sinrol@utamed.cl',
            'jti' => 'jwt-sin-rol',
        ]);
        unset($claims['rol']);

        $this->postJson('/api/auth/sso', ['token' => $this->makeJwt($claims)])
            ->assertStatus(401);
    }

    public function test_it_stores_the_carrera_reported_by_utamed(): void
    {
        $jwt = $this->makeJwt($this->claims([
            'sub' => '66666666-6',
            'rut' => '66666666-6',
            'email' => 'alumna@utamed.cl',
            'jti' => 'jwt-carrera',
            'rol' => 'ALUMNO',
            'carrera' => 'Diseño Multimedia',
        ]));

        $this->postJson('/api/auth/sso', ['token' => $jwt])->assertOk();

        $this->assertDatabaseHas('persona', [
            'Rut' => '66666666-6',
            'carrera' => 'Diseño Multimedia',
        ]);
    }

    public function test_it_syncs_the_asignaturas_reported_by_utamed(): void
    {
        // Una que SGEQ ya tenía (se reconoce por código y toma el nombre de UTAmed)
        // y otra nueva (se crea).
        $existente = Asignatura::create(['nombre' => 'Taller de video digital', 'codigo' => 'DM062']);

        $jwt = $this->makeJwt($this->claims([
            'sub' => '77777777-7',
            'rut' => '77777777-7',
            'email' => 'cursa@utamed.cl',
            'jti' => 'jwt-asignaturas',
            'asignaturas' => [
                ['codigo' => 'DM062', 'nombre' => 'Taller de Video Digital'],
                ['codigo' => 'DM075', 'nombre' => 'Taller de Música y Sonido'],
                ['codigo' => '', 'nombre' => 'Sin código: se descarta'],
            ],
        ]));

        $this->postJson('/api/auth/sso', ['token' => $jwt])->assertOk();

        $user = User::where('Email', 'cursa@utamed.cl')->firstOrFail();

        $this->assertEqualsCanonicalizing(['DM062', 'DM075'], $user->asignaturas()->pluck('codigo')->all());
        $this->assertSame('Taller de Video Digital', $existente->fresh()->nombre);
        $this->assertSame(2, Asignatura::count());
    }

    public function test_it_replaces_last_semester_asignaturas_on_each_login(): void
    {
        $claims = [
            'sub' => '88888888-8',
            'rut' => '88888888-8',
            'email' => 'semestre@utamed.cl',
        ];

        $primero = $this->makeJwt($this->claims($claims + [
            'jti' => 'jwt-semestre-1',
            'asignaturas' => [['codigo' => 'DM065', 'nombre' => 'Práctica Laboral I']],
        ]));
        $this->postJson('/api/auth/sso', ['token' => $primero])->assertOk();

        $segundo = $this->makeJwt($this->claims($claims + [
            'jti' => 'jwt-semestre-2',
            'asignaturas' => [['codigo' => 'DM071', 'nombre' => 'Práctica Laboral II']],
        ]));
        $this->postJson('/api/auth/sso', ['token' => $segundo])->assertOk();

        $user = User::where('Email', 'semestre@utamed.cl')->firstOrFail();

        $this->assertSame(['DM071'], $user->asignaturas()->pluck('codigo')->all());
    }

    /**
     * Claims validos, con lo que cada test necesite encima.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function claims(array $extra = []): array
    {
        return array_merge([
            'iss' => 'utamed',
            'aud' => 'sgeq',
            'exp' => now()->addMinute()->timestamp,
            'nombre1' => 'Ana',
            'apellido1' => 'Perez',
            'apellido2' => 'Soto',
            'rol' => 'ALUMNO',
        ], $extra);
    }

    private function generateKeys(): void
    {
        $this->privateKey = self::TEST_PRIVATE_KEY;
        $this->publicKeyPath = tempnam(sys_get_temp_dir(), 'utamed_sso_');

        file_put_contents($this->publicKeyPath, self::TEST_PUBLIC_KEY);
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function makeJwt(array $claims): string
    {
        return JWT::encode($claims, $this->privateKey, 'RS256');
    }
}