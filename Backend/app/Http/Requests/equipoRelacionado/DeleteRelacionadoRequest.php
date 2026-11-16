<?php

namespace App\Http\Requests\equipoRelacionado;

use Illuminate\Foundation\Http\FormRequest;

class DeleteRelacionadoRequest extends FormRequest
{
    /**
     * Eliminar relaciones entre equipos es una operación de administración
     * (igual que dar de baja un equipo en EquipoController::destroy).
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->isAdmin();
    }

    /**
     * tipo_relacion es opcional: si se omite, EquipoRelacionadoService::eliminarRelacion
     * elimina todas las relaciones entre ambos equipos.
     */
    public function rules(): array
    {
        return [
            'equipo_id' => 'required|integer',
            'relacionado_id' => 'required|integer',
            'tipo_relacion' => 'nullable|string|max:255',
        ];
    }
}
