<?php

namespace Tests\Feature\EquipoRelacionado;

use App\Http\Requests\equipoRelacionado\DeleteRelacionadoRequest;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Regresión: DELETE /api/equipos/relacion respondía 500 porque EquipoRelacionadoController::destroy
 * tipaba DeleteRelacionadoRequest, una clase que no existía. Sin tocar la base de datos.
 */
class DeleteRelacionadoRequestTest extends TestCase
{
    public function test_la_ruta_de_eliminar_relacion_resuelve_todas_sus_clases(): void
    {
        $route = collect(Route::getRoutes())->first(
            fn ($r) => $r->uri() === 'api/equipos/relacion' && in_array('DELETE', $r->methods(), true)
        );
        $this->assertNotNull($route, 'No se encontró la ruta DELETE api/equipos/relacion');

        [$controller, $method] = explode('@', $route->getActionName());
        foreach ((new \ReflectionMethod($controller, $method))->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $this->assertTrue(class_exists($type->getName()), "La clase {$type->getName()} no existe");
            }
        }
    }

    public function test_solo_autoriza_a_admin(): void
    {
        $this->assertFalse($this->autoriza(null), 'Un usuario no autenticado no debe estar autorizado.');
        $this->assertFalse($this->autoriza($this->usuarioFalso(false)), 'Un no-admin no debe eliminar relaciones.');
        $this->assertTrue($this->autoriza($this->usuarioFalso(true)), 'Un ADMIN debe poder eliminar relaciones.');
    }

    /** @dataProvider datosValidos */
    public function test_acepta_datos_validos(array $datos): void
    {
        $this->assertTrue($this->valida($datos));
    }

    /** @dataProvider datosInvalidos */
    public function test_rechaza_datos_invalidos(array $datos): void
    {
        $this->assertFalse($this->valida($datos));
    }

    public static function datosValidos(): array
    {
        return [
            'con tipo' => [['equipo_id' => 1, 'relacionado_id' => 2, 'tipo_relacion' => 'accesorio']],
            'sin tipo' => [['equipo_id' => 1, 'relacionado_id' => 2]],
        ];
    }

    public static function datosInvalidos(): array
    {
        return [
            'sin equipo_id'      => [['relacionado_id' => 2]],
            'sin relacionado_id' => [['equipo_id' => 1]],
            'id no entero'       => [['equipo_id' => 'abc', 'relacionado_id' => 2]],
            'tipo muy largo'     => [['equipo_id' => 1, 'relacionado_id' => 2, 'tipo_relacion' => str_repeat('x', 256)]],
        ];
    }

    private function valida(array $datos): bool
    {
        return Validator::make($datos, (new DeleteRelacionadoRequest())->rules())->passes();
    }

    private function autoriza(?object $user): bool
    {
        $request = new DeleteRelacionadoRequest();
        $request->setUserResolver(static fn () => $user);

        return $request->authorize();
    }

    /** Doble de usuario con isAdmin() controlable, sin depender de la BD. */
    private function usuarioFalso(bool $esAdmin): object
    {
        return new class($esAdmin) {
            public function __construct(private bool $esAdmin) {}

            public function isAdmin(): bool
            {
                return $this->esAdmin;
            }
        };
    }
}
