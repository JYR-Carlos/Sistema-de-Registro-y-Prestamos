<?php

use App\Support\Rut;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Deja todos los RUT de `persona` en el formato canónico ("12345678-9").
 *
 * POR QUÉ: el SSO con UTAmed identifica a la persona por su RUT, y UTAmed lo manda
 * siempre sin puntos. Los datos de acá vienen escritos con puntos ("11.111.111-1"),
 * así que la búsqueda no encontraría a nadie de los que ya están y le crearía una
 * cuenta nueva a cada uno. La columna tiene UNIQUE y el UNIQUE compara texto: las
 * dos escrituras conviven sin que la restricción se entere.
 *
 * De aquí en adelante el mutador de {@see \App\Models\Persona} mantiene el formato.
 *
 * SI HAY DUPLICADOS, ESTA MIGRACIÓN SE DETIENE. Que dos filas normalicen al mismo
 * RUT significa que la misma persona está dos veces, cada una con sus préstamos y
 * sanciones colgando. Fusionarlas es una decisión con consecuencias, no algo que
 * una migración deba resolver sola: informa cuáles son y espera.
 */
return new class extends Migration
{
    public function up(): void
    {
        $porCanonico = [];
        $colisiones = [];
        $porCorregir = [];

        DB::table('persona')
            ->select('idPersona', 'Rut')
            ->orderBy('idPersona')
            ->each(function ($fila) use (&$porCanonico, &$colisiones, &$porCorregir) {
                $canonico = Rut::normalizar($fila->Rut);

                if ($canonico === null) {
                    return;
                }

                if (isset($porCanonico[$canonico])) {
                    $colisiones[] = sprintf(
                        'persona #%d ("%s") y #%d ("%s") son el RUT %s',
                        $porCanonico[$canonico]['id'],
                        $porCanonico[$canonico]['rut'],
                        $fila->idPersona,
                        $fila->Rut,
                        $canonico
                    );

                    return;
                }

                $porCanonico[$canonico] = ['id' => $fila->idPersona, 'rut' => $fila->Rut];

                if ($canonico !== $fila->Rut) {
                    $porCorregir[$fila->idPersona] = $canonico;
                }
            });

        if ($colisiones !== []) {
            throw new RuntimeException(
                "No se puede normalizar el RUT: hay personas duplicadas que quedarían con el mismo valor.\n"
                . "Fusiona estos registros a mano y vuelve a correr la migración.\n  - "
                . implode("\n  - ", $colisiones)
            );
        }

        foreach ($porCorregir as $id => $canonico) {
            DB::table('persona')->where('idPersona', $id)->update(['Rut' => $canonico]);
        }
    }

    /**
     * Sin vuelta atrás: el formato con puntos no se puede reconstruir (no se sabe
     * cuáles filas lo tenían) y tampoco aporta nada recuperarlo.
     */
    public function down(): void
    {
        // Intencionalmente vacío.
    }
};
