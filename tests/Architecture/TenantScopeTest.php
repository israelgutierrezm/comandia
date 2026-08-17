<?php

declare(strict_types=1);

use App\Models\User;
use Tests\Fixtures\Models\ScopedFixture;
use Tests\Fixtures\Models\UnscopedFixture;
use Tests\Support\DomainModelDiscovery;

/**
 * TEST ESTRUCTURAL DE SCOPES DE TENANT
 *
 * Exigido por ADR-002 y por ARQUITECTURA_MAESTRA §11. Es la red de seguridad
 * contra el único fallo verdaderamente catastrófico del producto: que los datos
 * de un negocio se vean desde otro.
 *
 * Este archivo debe permanecer verde en todo momento. Si falla, no se
 * "arregla el test": se le pone el scope al modelo o se agrega a la lista de
 * excepciones CON justificación escrita.
 *
 * Estado en Fase 0: todavía no existen modelos de dominio, así que la
 * verificación principal recorre un conjunto vacío. Por eso los dos tests de
 * autoverificación de abajo no son opcionales: garantizan que el mecanismo
 * funciona antes de que haya algo que vigilar.
 */

/*
|--------------------------------------------------------------------------
| Excepciones justificadas
|--------------------------------------------------------------------------
|
| Un modelo entra aquí SÓLO si es global al SaaS por diseño y su tabla no
| contiene datos operativos de ningún tenant. Cada entrada lleva su razón.
|
| Toda alta en esta lista es una decisión de arquitectura: si dudas, no la
| agregues.
|
*/
$allowlist = [
    // Identidad global del SaaS (ESPECIFICACION_MAESTRA §4.1, capa 1): un
    // correo es único en toda la plataforma y una persona puede pertenecer a N
    // tenants independientes. La pertenencia —y por tanto el aislamiento— vive
    // en `tenant_memberships`, no aquí.
    //
    // Iteración 1: este modelo se mueve a App\Modules\Identity y la entrada de
    // esta lista se actualiza con su nuevo FQCN.
    User::class,
];

it('todo modelo de dominio declara el global scope de tenant', function () use ($allowlist) {
    $offenders = DomainModelDiscovery::withoutTenantScope($allowlist);

    expect($offenders)->toBe([], sprintf(
        "Estos modelos consultan la base de datos sin acotar por tenant (ADR-002, Regla A):\n  - %s\n\n".
        "Agrega el global scope de tenant al modelo, o —si es global al SaaS por diseño— inclúyelo en la lista de\n".
        'excepciones de tests/Architecture/TenantScopeTest.php con su justificación escrita.',
        implode("\n  - ", $offenders),
    ));
});

it('el detector reconoce un modelo que sí declara el scope', function () {
    expect(DomainModelDiscovery::hasTenantScope(ScopedFixture::class))->toBeTrue();
});

it('el detector reprueba un modelo que no declara el scope', function () {
    // Autoverificación: si esto pasara a true, el test principal estaría verde
    // por estar ciego, no por estar el proyecto correcto.
    expect(DomainModelDiscovery::hasTenantScope(UnscopedFixture::class))->toBeFalse();
});

it('el descubrimiento de modelos recorre app/ de verdad', function () {
    // El descubrimiento no debe depender de que existan modelos de dominio, pero
    // sí debe estar viendo el código de la aplicación. `App\Models\User` es el
    // único modelo del esqueleto de Laravel y sirve de canario: si desaparece de
    // los resultados, el recorrido de archivos se rompió.
    expect(DomainModelDiscovery::all())->toContain(User::class);
});
