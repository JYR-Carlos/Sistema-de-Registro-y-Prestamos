<?php

namespace Tests\Feature\Security;

use App\Http\Requests\Usuario\StoreUsuarioRequest;
use App\Http\Requests\Usuario\UpdateUsuarioRequest;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Regresión de C1 (escalada de privilegios vía PUT /api/usuarios/{id}).
 *
 * Antes, las rutas de mutación de usuarios sólo exigían auth:sanctum y los FormRequests
 * autorizaban a cualquiera (authorize() => true), de modo que un ALUMNO podía ascenderse
 * a ADMIN o cambiar la contraseña de otro usuario. Ahora la autorización está en dos capas:
 *   1) las rutas exigen el middleware 'admin' (estructural), y
 *   2) los FormRequests exigen isAdmin() (defensa en profundidad).
 *
 * Estas pruebas verifican ambas capas sin tocar la base de datos.
 */
class UsuarioAuthorizationTest extends TestCase
{
    /**
     * Las rutas que crean/editan/eliminan usuarios deben estar tras el middleware 'admin'.
     *
     * @dataProvider rutasDeMutacion
     */
    public function test_las_rutas_de_mutacion_de_usuarios_exigen_admin(string $method, string $uri): void
    {
        $middleware = $this->middlewareDe($method, $uri);

        $this->assertContains('auth:sanctum', $middleware, "$method $uri debe exigir auth:sanctum");
        $this->assertContains('admin', $middleware, "$method $uri debe exigir el middleware 'admin'");
    }

    /**
     * Las rutas de sólo lectura permanecen accesibles a cualquier usuario autenticado
     * (las usan flujos de alumno); no deben quedar tras 'admin'.
     *
     * @dataProvider rutasDeLectura
     */
    public function test_las_rutas_de_lectura_de_usuarios_no_exigen_admin(string $method, string $uri): void
    {
        $middleware = $this->middlewareDe($method, $uri);

        $this->assertContains('auth:sanctum', $middleware, "$method $uri debe exigir auth:sanctum");
        $this->assertNotContains('admin', $middleware, "$method $uri no debe quedar tras 'admin'");
    }

    public function test_update_usuario_request_solo_autoriza_a_admin(): void
    {
        $this->assertFalse(
            $this->autoriza(UpdateUsuarioRequest::class, null),
            'Un usuario no autenticado no debe estar autorizado.'
        );
        $this->assertFalse(
            $this->autoriza(UpdateUsuarioRequest::class, $this->usuarioFalso(false)),
            'Un ALUMNO (no admin) no debe estar autorizado a editar usuarios.'
        );
        $this->assertTrue(
            $this->autoriza(UpdateUsuarioRequest::class, $this->usuarioFalso(true)),
            'Un ADMIN debe estar autorizado a editar usuarios.'
        );
    }

    public function test_store_usuario_request_solo_autoriza_a_admin(): void
    {
        $this->assertFalse($this->autoriza(StoreUsuarioRequest::class, null));
        $this->assertFalse($this->autoriza(StoreUsuarioRequest::class, $this->usuarioFalso(false)));
        $this->assertTrue($this->autoriza(StoreUsuarioRequest::class, $this->usuarioFalso(true)));
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function rutasDeMutacion(): array
    {
        return [
            'crear'     => ['POST', 'api/usuarios'],
            'editar'    => ['PUT', 'api/usuarios/{id}'],
            'eliminar'  => ['DELETE', 'api/usuarios/{id}'],
            'reactivar' => ['POST', 'api/usuarios/{id}/reactivar'],
        ];
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function rutasDeLectura(): array
    {
        return [
            'listar' => ['GET', 'api/usuarios'],
            'ver'    => ['GET', 'api/usuarios/{id}'],
        ];
    }

    /** @return array<int,string> */
    private function middlewareDe(string $method, string $uri): array
    {
        foreach (Route::getRoutes() as $route) {
            if ($route->uri() === $uri && in_array($method, $route->methods(), true)) {
                return $route->gatherMiddleware();
            }
        }

        $this->fail("No se encontró la ruta $method $uri");
    }

    /**
     * Instancia el FormRequest con un userResolver fijo y devuelve el resultado de authorize().
     *
     * @param class-string<\Illuminate\Foundation\Http\FormRequest> $requestClass
     */
    private function autoriza(string $requestClass, ?object $user): bool
    {
        $request = new $requestClass();
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
