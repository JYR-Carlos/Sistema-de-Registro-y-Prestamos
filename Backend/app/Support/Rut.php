<?php

namespace App\Support;

/**
 * Formato único con el que se guarda el RUT: cuerpo sin puntos, guion y dígito
 * verificador en mayúscula — "12345678-9", "9123456-K".
 *
 * POR QUÉ EXISTE: `persona.Rut` es texto con UNIQUE, y el UNIQUE compara cadenas.
 * "12.345.678-9" y "12345678-9" son la misma persona y dos valores distintos, así
 * que la restricción no los ve iguales y la persona entra dos veces.
 *
 * La integración con UTAmed volvió esto urgente: el SSO identifica a la persona
 * por su RUT, y UTAmed lo manda siempre en formato canónico. Si acá la búsqueda no
 * normaliza, no encuentra a nadie de los que ya están —los datos vienen con
 * puntos— y le crea una cuenta nueva a cada uno.
 *
 * Por eso todo lo que escribe un RUT pasa por aquí, incluido el mutador de
 * {@see \App\Models\Persona} como última red.
 *
 * NORMALIZAR NO ES VALIDAR: si el valor no tiene forma de RUT se devuelve tal cual
 * (recortado) para que la validación lo rechace con un mensaje entendible.
 *
 * Es una copia del helper del mismo nombre en UTAmed. Los dos sistemas tienen que
 * escribir el RUT igual para reconocerse; si uno cambia, el otro también.
 */
final class Rut
{
    /** Cuerpo de 7 u 8 dígitos + DV, ya sin separadores ("123456789"). */
    private const PATRON_PLANO = '/^(\d{7,8})([0-9K])$/';

    /** El formato que se guarda: cuerpo, guion y DV. */
    public const PATRON_CANONICO = '/^\d{7,8}-[0-9K]$/';

    /**
     * Lleva cualquier escritura del RUT al formato canónico.
     *
     * Acepta puntos, espacios, guion o nada de eso, y la K en cualquier caja.
     */
    public static function normalizar(int|string|null $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $original = trim((string) $valor);

        if ($original === '') {
            return null;
        }

        $plano = strtoupper(preg_replace('/[^0-9kK]/u', '', $original) ?? '');

        if (preg_match(self::PATRON_PLANO, $plano, $partes) === 1) {
            return "{$partes[1]}-{$partes[2]}";
        }

        return $original;
    }

    /** ¿El valor ya está escrito como se guarda? */
    public static function esCanonico(?string $valor): bool
    {
        return $valor !== null && preg_match(self::PATRON_CANONICO, $valor) === 1;
    }

    /** ¿Es un RUT reconocible (7 u 8 dígitos + DV), venga como venga escrito? */
    public static function esValido(int|string|null $valor): bool
    {
        return self::esCanonico(self::normalizar($valor));
    }

    /**
     * Sólo dígitos y la K, sin separadores. Para comparar contra datos antiguos
     * que todavía puedan tener otro formato.
     */
    public static function soloDigitos(int|string|null $valor): string
    {
        return strtoupper(preg_replace('/[^0-9kK]/u', '', (string) $valor) ?? '');
    }
}
