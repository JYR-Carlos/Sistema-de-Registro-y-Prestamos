<?php

namespace App\Models;

use App\Support\Rut;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Persona extends Model
{
protected $primaryKey = 'idPersona';
    protected $table = 'persona';
    public $incrementing = true;

    protected $fillable = [
        'Nombre', 'apellido1', 'apellido2', 'Rut', 'Email', 'estado', 'telefono', 'celular', 'carrera'
    ];

    /**
     * El RUT se guarda siempre en el formato canónico ({@see Rut}): sin puntos y
     * con guion.
     *
     * La columna tiene UNIQUE, pero el UNIQUE compara texto: si una vía escribe
     * "12.345.678-9" y otra "12345678-9", la misma persona entra dos veces. El
     * mutador cierra esa puerta para cualquier escritura que pase por Eloquent
     * —registro, SSO, seeders— sin que cada una tenga que acordarse de normalizar.
     *
     * Ojo: los mutadores no alcanzan a las cláusulas `where`, así que las búsquedas
     * por RUT normalizan el término aparte.
     */
    protected function rut(): Attribute
    {
        return Attribute::make(
            set: fn (int|string|null $valor) => Rut::normalizar($valor),
        );
    }

    // Relación inversa UNO A UNO: Una persona tiene un usuario.
    public function user()
    {
        // hasOne(Modelo, clave_foranea_local, clave_primaria_local)
        return $this->hasOne(User::class, 'idPersona', 'idPersona');
    }
}
