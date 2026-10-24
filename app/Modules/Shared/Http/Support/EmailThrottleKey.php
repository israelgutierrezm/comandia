<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Support;

use Illuminate\Support\Facades\DB;

/**
 * La parte del correo en la llave de un límite de intentos (D381, D387, ARQUITECTURA §8).
 *
 * Tiene que igualar dos correos exactamente cuando la base los iguala. Las columnas de correo usan una intercalación que
 * no distingue mayúsculas ni acentos (`ēma@…` encuentra la cuenta de `ema@…`); si la llave sí los distinguiera, cada
 * variante estrenaría su propio contador sobre la misma cuenta y el tope «por correo + IP» se multiplicaría.
 *
 * Por eso la llave sale del **peso** con que la propia base compara (`WEIGHT_STRING`), no de una imitación en PHP. Se
 * intentó imitarla (D381: `Str::ascii`) y no alcanza: la intercalación iguala con letras ASCII más de dos mil caracteres
 * que la regla `email` acepta y que `Str::ascii` borra o no traduce —`ǧ`, `ȁ`, ancho completo `ａ`, matemáticas `𝐚`,
 * los dígitos de toda escritura (`١`, `१`)—, y NFKC o NFKD dejan alrededor de mil. El peso coincide con `=` por
 * construcción. El limitador de Laravel limpia la llave con `htmlentities`, que sólo alcanza a Latin-1 (`é`, `ñ`).
 *
 * Cuesta una consulta sin tabla por intento. Va en SHA-256 porque el peso crece con el correo (una ligadura árabe se
 * expande a decenas de pesos), así que la llave es opaca: para limpiarla a mano, `RateLimiter::clear()` con esta misma
 * pieza. `EmailCollationTest` exige que las columnas de correo usen esta intercalación: si alguna cambiara, la llave
 * dejaría de comparar como ella.
 */
final class EmailThrottleKey
{
    public const COLLATION = 'utf8mb4_0900_ai_ci';

    public static function of(string $email): string
    {
        $peso = DB::selectOne(
            'SELECT HEX(WEIGHT_STRING(CONVERT(? USING utf8mb4) COLLATE '.self::COLLATION.')) AS peso',
            [trim($email)],
        );

        return hash('sha256', (string) $peso->peso);
    }
}
