<?php

namespace Tests\Feature\Prestamo;

use App\Models\Asignatura;
use App\Models\Persona;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Un alumno sólo puede asociar un préstamo a una asignatura que cursa o ayuda.
 *
 * Antes la lista del formulario era fija y el backend aceptaba cualquier
 * asignatura, así que un alumno podía pedir equipos a nombre de un ramo que no
 * cursaba. Ahora las suyas las sincroniza el SSO de UTAmed (asignatura_user).
 */
class AsignaturasDelAlumnoTest extends TestCase
{
    use RefreshDatabase;

    private Asignatura $propia;

    private Asignatura $ajena;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['ALUMNO', 'ADMIN', 'SUPER_USUARIO'] as $nombre) {
            Rol::create(['Nombre' => $nombre]);
        }

        $this->propia = Asignatura::create(['nombre' => 'Taller de Video Digital', 'codigo' => 'DM062']);
        $this->ajena = Asignatura::create(['nombre' => 'Taller de Música y Sonido', 'codigo' => 'DM075']);
    }

    public function test_el_alumno_solo_ve_sus_asignaturas(): void
    {
        Sanctum::actingAs($this->usuario('ALUMNO', [$this->propia]));

        $this->getJson('/api/asignaturas')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.idAsignatura', $this->propia->idAsignatura);
    }

    public function test_el_admin_ve_todas_las_asignaturas(): void
    {
        Sanctum::actingAs($this->usuario('ADMIN'));

        $this->getJson('/api/asignaturas')
            ->assertOk()
            ->assertJsonCount(2);
    }

    public function test_el_alumno_no_puede_pedir_para_una_asignatura_que_no_cursa(): void
    {
        Sanctum::actingAs($this->usuario('ALUMNO', [$this->propia]));

        $this->postJson('/api/prestamos', $this->solicitud($this->ajena->idAsignatura))
            ->assertStatus(422)
            ->assertJsonValidationErrors('asignatura');
    }

    public function test_tampoco_por_nombre(): void
    {
        Sanctum::actingAs($this->usuario('ALUMNO', [$this->propia]));

        $this->postJson('/api/prestamos', $this->solicitud('Taller de Música y Sonido'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('asignatura');
    }

    /**
     * Los casos que sí pasan se comprueban con un `tipo` inválido: la validación
     * corta igual y deja ver que el error es de `tipo` y no de `asignatura`, sin
     * tener que armar un préstamo completo.
     */
    public function test_el_alumno_puede_pedir_para_una_asignatura_que_cursa(): void
    {
        Sanctum::actingAs($this->usuario('ALUMNO', [$this->propia]));

        $this->postJson('/api/prestamos', $this->solicitud($this->propia->idAsignatura, tipo: 'X'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('tipo')
            ->assertJsonMissingValidationErrors('asignatura');
    }

    public function test_el_alumno_puede_pedir_como_otros(): void
    {
        Sanctum::actingAs($this->usuario('ALUMNO'));

        $this->postJson('/api/prestamos', $this->solicitud('OTROS', tipo: 'X'))
            ->assertStatus(422)
            ->assertJsonMissingValidationErrors('asignatura');
    }

    public function test_el_admin_puede_pedir_para_cualquier_asignatura(): void
    {
        Sanctum::actingAs($this->usuario('ADMIN'));

        $this->postJson('/api/prestamos', $this->solicitud($this->ajena->idAsignatura, tipo: 'X'))
            ->assertStatus(422)
            ->assertJsonMissingValidationErrors('asignatura');
    }

    /**
     * @param  list<Asignatura>  $asignaturas
     */
    private function usuario(string $rol, array $asignaturas = []): User
    {
        static $n = 0;
        $n++;

        $persona = Persona::create([
            'Nombre' => 'Ana',
            'apellido1' => 'Pérez',
            'Rut' => sprintf('%08d-%d', 10000000 + $n, $n % 10),
            'Email' => "usuario{$n}@utamed.cl",
            'estado' => 'ACTIVO',
        ]);

        $user = User::create([
            'idPersona' => $persona->idPersona,
            'Contrasena' => Hash::make('secreto'),
            'estadoSancion' => 'ACTIVO',
            'estado' => 'ACTIVO',
            'bloqueado' => false,
            'Email' => $persona->Email,
        ]);

        $user->roles()->attach(Rol::where('Nombre', $rol)->value('idRol'));
        $user->asignaturas()->attach(array_map(fn (Asignatura $a) => $a->idAsignatura, $asignaturas));

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function solicitud(int|string $asignatura, string $tipo = 'FUERA'): array
    {
        return [
            'tipo' => $tipo,
            'asignatura' => $asignatura,
            'equipos' => [['idTipoEquipo' => 1, 'cantidad' => 1]],
        ];
    }
}
