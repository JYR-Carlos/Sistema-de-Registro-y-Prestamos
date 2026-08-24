<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Permite el paso solo a usuarios con rol ADMIN o SUPER_USUARIO.
 *
 * Se usa en los grupos de gestión de categorías y configuraciones, donde la política
 * vigente (validada en los controladores y FormRequests mediante isAdminOrSuper) admite
 * ambos roles. A diferencia de comprobar el rol dentro de cada método, aplicar este
 * middleware al grupo hace que la autorización sea estructural: una ruta nueva que se
 * añada al grupo queda protegida aunque su método olvide validar el rol.
 */
class AdminOrSuperMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        if (!Auth::check()) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        $user = Auth::user();

        if (!method_exists($user, 'isAdminOrSuper') || !$user->isAdminOrSuper()) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        return $next($request);
    }
}
