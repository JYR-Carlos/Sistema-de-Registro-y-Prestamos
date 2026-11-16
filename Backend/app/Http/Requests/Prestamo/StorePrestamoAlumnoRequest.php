<?php

namespace App\Http\Requests\Prestamo;

use App\Models\Asignatura;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StorePrestamoAlumnoRequest extends FormRequest
{
    /** Valor del formulario para pedir sin asignatura, con un motivo libre. */
    public const SIN_ASIGNATURA = 'OTROS';

    public function authorize(): bool
    {
        return true; // ya validas con auth
    }

    public function rules(): array
    {
        return [
            'tipo'         => 'required|in:DENTRO,FUERA',
            'asignatura'   => ['nullable', $this->asignaturaPropia(...)],
            'motivo'       => 'nullable|string',
            'observacion'  => 'nullable|string',
            'fecha_inicio' => 'nullable|date',
            'fecha_fin'    => 'nullable|date|after_or_equal:fecha_inicio',
            'bloques'      => 'required_if:tipo,DENTRO|array',
            'bloques.*'    => 'integer|exists:bloques,idBloque',
            'equipos'      => 'required|array|min:1',
            'integrantes'  => 'nullable|array',
            'integrantes.*' => 'integer|distinct|exists:users,idUser',
            'grupo_id'     => 'nullable|integer|exists:grupos,id',
        ];
    }

    /**
     * ID de la asignatura elegida, o null si se pidió como OTROS o no vino.
     *
     * El formulario manda el ID; se sigue aceptando el nombre por compatibilidad.
     */
    public function idAsignatura(): ?int
    {
        $valor = $this->input('asignatura');

        if ($valor === null || $valor === self::SIN_ASIGNATURA || trim((string) $valor) === '') {
            return null;
        }

        if (is_numeric($valor)) {
            return (int) $valor;
        }

        $id = Asignatura::whereRaw('LOWER(nombre) = ?', [mb_strtolower(trim((string) $valor))])
            ->value('idAsignatura');

        return $id === null ? null : (int) $id;
    }

    /**
     * Un alumno sólo puede asociar el préstamo a una asignatura que cursa o ayuda
     * (las sincroniza el SSO de UTAmed). Admin y super usuario, a cualquiera.
     */
    private function asignaturaPropia(string $attribute, mixed $value, Closure $fail): void
    {
        $user = $this->user();

        if ($value === self::SIN_ASIGNATURA || $user === null || $user->isAdminOrSuper()) {
            return;
        }

        $id = $this->idAsignatura();

        if ($id === null || !$user->asignaturas()->whereKey($id)->exists()) {
            $fail('Solo puedes pedir equipos para una asignatura que estés cursando o en la que seas ayudante.');
        }
    }
}
