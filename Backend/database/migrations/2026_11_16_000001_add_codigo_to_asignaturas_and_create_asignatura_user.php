<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Asignaturas que cada usuario cursa o ayuda, para que un alumno sólo pueda
 * asociar un préstamo a una de las suyas.
 *
 * El dato viene de UTAmed en el token del SSO ({@see \App\Http\Controllers\Auth\SsoController}),
 * y las asignaturas se reconocen por `codigo`, el `cod_asignatura` de UTAmed: el
 * nombre no sirve para cruzar porque los dos sistemas lo escriben distinto.
 */
return new class extends Migration
{
    /**
     * Código en UTAmed de las asignaturas que SGEQ ya tenía, para que el SSO las
     * reconozca en vez de crear duplicados y los préstamos antiguos sigan
     * colgando de la misma fila.
     *
     * "Practica Laboral Segundo año" queda sin código a propósito: en UTAmed son
     * dos asignaturas, una por semestre (DM065 Práctica Laboral I y DM071
     * Práctica Laboral II). Cada una entra por el SSO como propia y la fila
     * antigua queda sólo para el historial.
     */
    private const CODIGOS_EXISTENTES = [
        'Taller de fotografía Digital' => 'DM052',
        'Taller de fotografía Publicitaria' => 'DM058',
        'Taller de Producción Multimedia I' => 'DM079',
        'Taller de Video Digital' => 'DM062',
        'Taller de Medios de Comunicación' => 'DM072',
        'Taller de Música y Sonido' => 'DM075',
        'Laboratorio de Video Digital' => 'DM076',
        'Practica Profesional Cuarto año' => 'DM089',
    ];

    public function up(): void
    {
        Schema::table('asignaturas', function (Blueprint $table) {
            $table->string('codigo', 20)->nullable()->unique()->after('idAsignatura');
        });

        foreach (self::CODIGOS_EXISTENTES as $nombre => $codigo) {
            // Por id y no por nombre: si el nombre estuviera repetido, el UNIQUE de
            // `codigo` haría fallar la migración. Se queda con la fila más antigua.
            $id = DB::table('asignaturas')
                ->where('nombre', $nombre)
                ->orderBy('idAsignatura')
                ->value('idAsignatura');

            if ($id !== null) {
                DB::table('asignaturas')->where('idAsignatura', $id)->update(['codigo' => $codigo]);
            }
        }

        Schema::create('asignatura_user', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('idUser');
            $table->unsignedBigInteger('idAsignatura');

            $table->foreign('idUser')->references('idUser')->on('users')->cascadeOnDelete();
            $table->foreign('idAsignatura')->references('idAsignatura')->on('asignaturas')->cascadeOnDelete();
            $table->unique(['idUser', 'idAsignatura']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asignatura_user');

        Schema::table('asignaturas', function (Blueprint $table) {
            $table->dropUnique(['codigo']);
            $table->dropColumn('codigo');
        });
    }
};
