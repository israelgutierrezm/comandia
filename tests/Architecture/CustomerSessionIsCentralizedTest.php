<?php

declare(strict_types=1);

use Tests\Support\SourceScanner;

/**
 * CANDADO: LA SESIÓN DEL CLIENTE DE LA TIENDA SE LEE EN UN SOLO LUGAR (D381)
 *
 * `CustomerSession` revisa la huella de la contraseña y el estado del cliente en cada lectura: así restablecer la
 * contraseña o inactivar al cliente cierra sus otras sesiones. Un controlador que leyera el guardia `customer` por su
 * cuenta dejaría pasar a esas sesiones viejas sin que ninguna prueba lo notara.
 */
it('sólo CustomerSession lee el guardia de clientes', function () {
    $infracciones = SourceScanner::findUsages(
        [
            "guard('customer')", 'guard("customer")',
            "auth('customer')", 'auth("customer")',
            "user('customer')", 'user("customer")',
            "shouldUse('customer')", 'shouldUse("customer")',
            // Middleware `auth:customer` y `auth:web,customer`.
            ":customer'", ':customer"', ",customer'", ',customer"',
        ],
        ['app/Modules/Ecommerce/Http/Support/CustomerSession.php'],
    );

    expect($infracciones)->toBe([], sprintf(
        "Estos archivos leen el guardia `customer` por fuera de CustomerSession (D381):\n  - %s\n\n".
        'Usa App\Modules\Ecommerce\Http\Support\CustomerSession::current(), que revisa la huella y el estado.',
        implode("\n  - ", $infracciones),
    ));
});
