<?php

declare(strict_types=1);

use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use Illuminate\Support\Facades\Artisan;

/**
 * LA PURGA DEL NEGOCIO DE DEMOSTRACIÓN TIENE QUE SEGUIR FUNCIONANDO
 *
 * `comandia:demo:seed --fresh` borra el negocio anterior recorriendo una lista de tablas **en orden inverso a sus
 * dependencias**, escrita a mano. Es la única forma de hacerlo —las tablas inmutables rechazan el borrado por diseño y
 * este comando es el único autorizado a saltárselo— y por eso mismo es una lista que cada iteración puede romper sin que
 * nadie lo note.
 *
 * ## Y la rompió
 *
 * Al cerrar la Iteración 3, `--fresh` dejó de poder purgar: cuatro tablas nuevas de documento apuntan a
 * `stock_movements` con `RESTRICT`, y la lista intentaba borrar el kardex antes que ellas. El mensaje era un error de
 * clave foránea de MySQL sin ninguna pista de qué tabla faltaba en la lista.
 *
 * Lo peor es cuándo se descubre: la suite estaba en verde —ninguna prueba corría el comando— y el fallo aparece cuando
 * alguien quiere volver a sembrar sus datos de demostración, o sea preparando una demo comercial.
 *
 * ## Por qué siembra ANTES de purgar
 *
 * Porque una purga sobre una base vacía no prueba nada: no hay filas que sostengan a otras, así que cualquier orden
 * funciona. El valor está en purgar un negocio **con datos en las tablas nuevas**, y la forma más fiel de tenerlos es
 * dejar que el propio sembrador los cree.
 */
it('siembra y vuelve a sembrar el negocio de demostración sin romperse', function () {
    // Primera siembra: crea el negocio con su catálogo, sus recetas y sus costos.
    expect(Artisan::call('comandia:demo:seed', ['--force' => true]))->toBe(0);

    $primero = Tenant::query()->withoutGlobalScopes()->where('name', 'Fonda La Comandia')->sole();

    // Segunda con `--fresh`: purga el anterior y siembra otro. Es la operación que se rompió.
    expect(Artisan::call('comandia:demo:seed', ['--fresh' => true, '--force' => true]))->toBe(0);

    $segundo = Tenant::query()->withoutGlobalScopes()->where('name', 'Fonda La Comandia')->sole();

    // Otro negocio, y el anterior desapareció por completo: si la purga hubiera fallado a medias, la transacción se
    // revertiría y seguiría existiendo el primero.
    expect($segundo->id)->not->toBe($primero->id)
        ->and(Tenant::query()->withoutGlobalScopes()->whereKey($primero->id)->exists())->toBeFalse();
});

/*
 * ## Hubo un segundo candado aquí, y estaba mal
 *
 * Escribí uno que exigía que **toda** tabla acotada por negocio estuviera en la lista de purga, leyéndola por reflexión.
 * Encontró diez tablas «faltantes» de las Iteraciones 1 y 2 —ajustes, perfiles, terminales, suscripciones— y ninguna
 * faltaba de verdad: su FK a `tenants` es `CASCADE`, así que se van solas al borrar el negocio. La lista sólo necesita
 * las que **bloquearían** el borrado, o sea las que tienen algún camino `RESTRICT`.
 *
 * O sea que el candado pedía trabajo inútil, y eso no es inocuo: un candado que exige lo incorrecto se acaba apagando, y
 * cuando alguien lo apaga se lleva por delante al que sí servía.
 *
 * La prueba de arriba —purgar de verdad un negocio con datos— es el nivel correcto: es la que falló antes del arreglo y
 * la que pasa después. Distinguir un camino `CASCADE` de uno `RESTRICT` exigiría recorrer el esquema entero, y sería más
 * código del que protege.
 */

/**
 * EL NEGOCIO DE DEMOSTRACIÓN TIENE QUE PODER AUTORIZAR CON PIN
 *
 * Sin **ninguna** persona con PIN dado de alta, el diálogo de autorización por PIN sólo puede responder una cosa:
 * «código o PIN incorrectos». Ese mensaje es el correcto —ADR-008 exige que un código inexistente y un PIN equivocado
 * digan lo mismo, para que nadie pueda enumerar códigos válidos— pero convierte el diálogo en un callejón sin salida:
 * quien lo lee concluye que escribió mal el PIN, no que no hay a quién pedírselo.
 *
 * Lo encontré intentando autorizar una merma sobre el umbral en el negocio de demostración, con la pantalla insistiendo
 * en que mi PIN estaba mal. O sea que la autorización por PIN, una de las cosas que este producto vende, era justo la
 * que no se podía demostrar (D224).
 *
 * La prueba mira el **hash**, no el PIN en claro: comprobar que el PIN es «4321» exigiría reimplementar aquí la
 * verificación, y lo que hace falta saber es que existe un perfil con credencial utilizable.
 */
it('siembra un PIN de autorización para el propietario', function () {
    expect(Artisan::call('comandia:demo:seed', ['--force' => true]))->toBe(0);

    $tenant = Tenant::query()->withoutGlobalScopes()->where('name', 'Fonda La Comandia')->sole();

    // El PIN vive en la MEMBRESÍA y no en el perfil laboral: el perfil es PII de contratación (§4.1, capa 3) y el PIN es
    // una credencial operativa. Confundirlos fue mi primer diagnóstico y era falso — `employee_profiles` estaba vacío
    // porque nadie había capturado datos laborales, no porque faltara el PIN.
    $membresia = TenantMembership::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $tenant->id)
        ->whereNotNull('pin_hash')
        ->first();

    expect($membresia)->not->toBeNull('El sembrador no dejó ningún PIN: no hay a quién pedirle una autorización.')
        // Y el código, porque un PIN sin código de empleado no se puede teclear en ninguna terminal — es lo que
        // `ManageMembershipPin::set()` exige.
        ->and($membresia->employee_code)->not->toBeNull();
});
