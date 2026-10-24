<?php

declare(strict_types=1);

use App\Modules\Shared\Http\Support\EmailThrottleKey;
use Illuminate\Support\Facades\DB;

/**
 * La llave de los límites por correo compara como las columnas donde se busca la cuenta (D387, ARQUITECTURA §8).
 *
 * `EmailThrottleKey` arma la parte del correo con el peso de una intercalación fija. Si una de estas columnas pasara a
 * otra —una que sí distinga acentos, o la de otro idioma—, la llave dejaría de igualar lo que la búsqueda iguala y cada
 * variante del correo volvería a estrenar contador, sin que ninguna prueba de acceso lo notara: todas usan correos que
 * las dos intercalaciones igualan igual.
 */
it('las columnas de correo de las cuentas usan la intercalación de la llave de los límites', function (string $tabla) {
    $intercalacion = DB::selectOne(
        "SELECT COLLATION_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'email'",
        [$tabla],
    )?->c;

    expect($intercalacion)->toBe(EmailThrottleKey::COLLATION);
})->with([
    'personal (acceso, token de la app, pedir enlace)' => 'users',
    'super administradores' => 'platform_admins',
    'clientes de la tienda' => 'customers',
]);

it('la llave iguala lo que la base iguala, aunque Str::ascii no lo traduzca', function () {
    // Del barrido de D387: la base iguala con `ana@fonda.mx` cada una de éstas, y `Str::ascii` las borraba.
    $variantes = ['ȁnā@fonda.mx', 'ａｎａ@fonda.mx', '𝐚𝐧𝐚@fonda.mx', 'ANA@FONDA.MX', '  ana@fonda.mx  '];

    foreach ($variantes as $variante) {
        expect(EmailThrottleKey::of($variante))->toBe(EmailThrottleKey::of('ana@fonda.mx'), $variante);
    }

    // Y no iguala lo que la base distingue: otra letra, o una letra de otra escritura que sólo se le parece.
    expect(EmailThrottleKey::of('ena@fonda.mx'))->not->toBe(EmailThrottleKey::of('ana@fonda.mx'));
    expect(EmailThrottleKey::of('аna@fonda.mx'))->not->toBe(EmailThrottleKey::of('ana@fonda.mx'));
});
