<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class ResetPasswordController extends Controller
{
    /** Minutos de validez de un token de restablecimiento. */
    private const TOKEN_TTL_MINUTES = 60;

    public function reset(Request $request)
    {
        // Aceptar ambas formas de "email"
        $email = $request->input('email') ?? $request->input('Email');

        $request->validate([
            'token' => 'required',
            'email' => 'required_without:Email|email',
            'Email' => 'required_without:email|email',
            'password' => 'required|min:8|confirmed',
        ]);

        // Buscar el registro de password_resets por email
        $record = DB::table('password_resets')
            ->where('email', $email)
            ->first();

        if (!$record) {
            return response()->json(['message' => 'Token inválido o expirado (no encontrado en BD).'], 400);
        }

        // Verificar expiración (tokens válidos por TOKEN_TTL_MINUTES).
        // Comparación sign-safe: created_at viene del query builder como string, se parsea a Carbon.
        if ($this->estaExpirado($record->created_at)) {
            DB::table('password_resets')->where('email', $email)->delete();
            return response()->json(['message' => 'El token ha expirado. Solicita uno nuevo.'], 400);
        }

        // El token se almacena hasheado (ForgotPasswordController usa Hash::make).
        // Solo se compara contra el hash; sin fallback en texto plano.
        if (!Hash::check($request->token, $record->token)) {
            return response()->json(['message' => 'El token no coincide.'], 400);
        }

        // Buscar usuario en tu tabla (campo "Email" con mayúscula)
        $user = User::where('Email', $email)->first();

        if (!$user) {
            return response()->json(['message' => 'Usuario no encontrado.'], 404);
        }

        // Actualizar la contraseña
        $user->Contrasena = Hash::make($request->password);
        $user->save();

        // Eliminar el token usado
        DB::table('password_resets')->where('email', $email)->delete();

        return response()->json(['message' => 'Contraseña restablecida correctamente.']);
    }

    public function validateToken($token)
    {
        // El enlace de restablecimiento solo transporta el token (no el email), así que
        // hay que localizar el registro por token. Como el token está hasheado, se recorren
        // las filas comparando el hash. Para acotar el trabajo criptográfico y cerrar la
        // vía de enumeración, primero se purgan los tokens expirados: el bucle solo
        // procesa tokens vivos (normalmente uno por email, pocos en total).
        $limite = now()->subMinutes(self::TOKEN_TTL_MINUTES);
        DB::table('password_resets')
            ->where('created_at', '<', $limite)
            ->delete();

        $records = DB::table('password_resets')->get();

        foreach ($records as $record) {
            // Solo hash; sin fallback en texto plano.
            if (Hash::check($token, $record->token)) {
                return response()->json(['email' => $record->email]);
            }
        }

        return response()->json(['message' => 'Token inválido o expirado.'], 400);
    }

    /**
     * Indica si un token creado en $createdAt ya superó su ventana de validez.
     *
     * @param  string|null  $createdAt  Valor crudo de created_at devuelto por el query builder.
     */
    private function estaExpirado(?string $createdAt): bool
    {
        if ($createdAt === null) {
            return true;
        }

        return Carbon::parse($createdAt)->lt(now()->subMinutes(self::TOKEN_TTL_MINUTES));
    }
}
