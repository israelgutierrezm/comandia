<?php

declare(strict_types=1);

use App\Console\Commands\SeedDemoTenantCommand;
use Illuminate\Support\Facades\DB;

/**
 * CANDADO: toda tabla con `tenant_id` desaparece al sembrar el demo con `--fresh`.
 *
 * ## El agujero que cierra, y por qué el otro candado no lo veía
 *
 * Ya existía `DemoSeederPurgeTest`: siembra el negocio de demostración, corre `--fresh` y comprueba que la purga no
 * revienta. Ha atrapado el fallo tres iteraciones seguidas y aun así **dejó pasar trece tablas**.
 *
 * La razón es que sólo purga lo que el sembrador crea. Las tablas que se llenan **operando** —pagos, descuentos,
 * gastos, propinas liquidadas, trabajos de impresión, movimientos de crédito— están vacías en un tenant recién
 * sembrado, así que su `DELETE` nunca choca con nada y la prueba pasa en verde con la lista incompleta.
 *
 * El fallo aparece justo donde más molesta: alguien abre el navegador, opera un rato para probar, y `--fresh` deja de
 * funcionar con un error de clave foránea que no dice qué tabla falta.
 *
 * ## Por qué NO basta con «cascadea del tenant»
 *
 * La purga termina borrando la fila de `tenants`, y casi toda tabla de dominio tiene `tenant_id` con `ON DELETE
 * CASCADE`. Escribí la primera versión de este candado dando eso por bueno — y quedó **inservible**: con la lista de
 * purga VACÍA seguía pasando para 78 de 80 tablas.
 *
 * El razonamiento era circular. Ese `DELETE FROM tenants` sólo funciona **porque la lista ya vació todo antes**: hay
 * 160 claves foráneas `RESTRICT` entre tablas hermanas —`expenses.branch_id` retiene a `branches`,
 * `financial_movements.pos_session_id` retiene a `pos_sessions`— y una cascada no las atraviesa. Borrar el tenant de
 * golpe, sin la lista, falla. Usar el último paso como prueba de que los anteriores sobran es suponer lo que se quiere
 * demostrar.
 *
 * ## Las dos formas legítimas, que son distintas
 *
 *   1. **La lista la alcanza.** Está en `purgeTables()`, o cae por cascada de algo que sí está: `employee_profiles`
 *      cuelga de `tenant_memberships` con `CASCADE`, así que borrar la membresía se la lleva.
 *
 *   2. **Cuelga del tenant y nada la retiene.** Cascadea de `tenants` y **no tiene ninguna clave foránea que no sea
 *      cascada**. Son las tablas de configuración del negocio —`tenant_settings`, `tenant_modules`, `tenant_limits`,
 *      `subscriptions`—: no apuntan a nadie más, así que nada puede bloquear su borrado y el último `DELETE` se las
 *      lleva de verdad.
 *
 * La segunda condición es la que hace que esto sirva. `expenses` también cascadea del tenant, pero apunta con
 * `RESTRICT` a sucursales, membresías, métodos de pago y sesiones: sacarla de la lista la marca como no cubierta, que
 * es exactamente lo que debe pasar. Se comprobó rompiéndolo.
 *
 * ## Lo que este candado NO comprueba
 *
 * El ORDEN. Que una tabla esté en la lista no dice que esté en el sitio correcto respecto a sus claves foráneas, y eso
 * lo sigue comprobando `DemoSeederPurgeTest` corriendo la purga de verdad. Los dos hacen falta: éste ve las tablas que
 * **faltan**, aquél ve las que están **mal colocadas**.
 */

/**
 * Las claves foráneas del esquema, con su regla de borrado.
 *
 * @return list<object>
 */
function clavesForaneas(): array
{
    return array_map(
        fn (object $fk): object => (object) [
            'hija' => $fk->hija,
            'padre' => $fk->padre,
            'cascada' => $fk->regla === 'CASCADE',
        ],
        DB::select(
            'SELECT k.TABLE_NAME AS hija, k.REFERENCED_TABLE_NAME AS padre, r.DELETE_RULE AS regla
             FROM information_schema.KEY_COLUMN_USAGE k
             JOIN information_schema.REFERENTIAL_CONSTRAINTS r
               ON r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
             WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL',
        ),
    );
}

/**
 * Las tablas que las raíces alcanzan: ellas mismas, más todo lo que cae por cascada.
 *
 * Se recorre el grafo hasta que no crece, porque una cascada puede ser de dos saltos: `membership_branch_scopes` cuelga
 * de la membresía, que cuelga de lo que sí se borra. Quedarse en un nivel dejaría fuera justo las más lejanas.
 *
 * @param  list<string>  $raices
 * @param  list<object>  $fks
 * @return array<string, true>
 */
function alcanzadasPor(array $raices, array $fks): array
{
    $alcanzadas = array_fill_keys($raices, true);

    do {
        $crecio = false;

        foreach ($fks as $fk) {
            if ($fk->cascada && isset($alcanzadas[$fk->padre]) && ! isset($alcanzadas[$fk->hija])) {
                $alcanzadas[$fk->hija] = true;
                $crecio = true;
            }
        }
    } while ($crecio);

    return $alcanzadas;
}

/**
 * La lista de purga, leída del comando.
 *
 * Vive en un método privado a propósito —para que los candados la LEAN en lugar de copiarla— así que se alcanza por
 * reflexión. Dos listas que dicen lo mismo se desincronizan, que es justo el problema que se está evitando.
 *
 * @return list<string>
 */
function listaDePurga(): array
{
    $metodo = new ReflectionMethod(new SeedDemoTenantCommand, 'purgeTables');
    $metodo->setAccessible(true);

    return $metodo->invoke(new SeedDemoTenantCommand);
}

/**
 * Las tablas que sólo existen en la base de PRUEBAS.
 *
 * `tests/Fixtures/database/migrations` crea dos tablas con `tenant_id` para que el candado de aislamiento tenga sobre
 * qué probar el global scope. No existen en desarrollo ni en producción —se comprobó: cero de dos en `comandia`, dos de
 * dos en `comandia_testing`— así que no pueden romper un `--fresh`.
 *
 * Es la única excepción, y de las que no caducan: el día que dejen de ser de prueba, dejarán de estar en esa carpeta.
 *
 * @var list<string>
 */
$soloEnPruebas = ['scoped_fixtures', 'ulid_fixtures'];

it('toda tabla con tenant_id desaparece al purgar el demo', function () use ($soloEnPruebas) {
    $fks = clavesForaneas();

    // `roles` se borra en su propia línea, fuera del bucle de la lista, porque Spatie no la considera de dominio.
    // Cuenta como raíz igual: se borra explícitamente, y `model_has_roles` y compañía cascadean de ella.
    $alcanzadas = alcanzadasPor([...listaDePurga(), 'roles'], $fks);

    // Las que retienen a alguien: tienen al menos una clave foránea que NO es cascada.
    $retienen = [];

    foreach ($fks as $fk) {
        if (! $fk->cascada) {
            $retienen[$fk->hija] = true;
        }
    }

    // Las que cuelgan del tenant sin retener a nadie. La segunda mitad es la que da valor a la regla: sin ella, esto
    // aceptaría una lista de purga vacía.
    $porElTenant = array_filter(
        alcanzadasPor(['tenants'], $fks),
        fn (bool $_, string $tabla): bool => ! isset($retienen[$tabla]),
        ARRAY_FILTER_USE_BOTH,
    );

    $conTenant = collect(DB::select(
        'SELECT DISTINCT TABLE_NAME AS t FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = ?',
        ['tenant_id'],
    ))->pluck('t')->all();

    $faltantes = array_values(array_diff(
        $conTenant,
        array_keys($alcanzadas),
        array_keys($porElTenant),
        $soloEnPruebas,
    ));

    sort($faltantes);

    expect($faltantes)->toBe([], sprintf(
        "Estas tablas llevan `tenant_id` y NO desaparecen al sembrar el demo con --fresh:\n  - %s\n\n".
        "Agrégalas a `SeedDemoTenantCommand::purgeTables()`, en el sitio que respete sus claves foráneas.\n\n".
        "Si se quedan fuera, `--fresh` seguirá funcionando mientras nadie use el sistema — y fallará con un error de\n".
        'clave foránea en cuanto alguien opere en el navegador, que es cuando de verdad hace falta.',
        implode("\n  - ", $faltantes),
    ));
});

it('la lista de purga no nombra tablas que ya no existen', function () {
    // El otro lado de la misma moneda: una tabla renombrada o eliminada deja un nombre muerto en la lista, y el
    // `DELETE` contra una tabla inexistente revienta la purga entera — con un error que tampoco dice cuál sobra.
    $existentes = collect(DB::select(
        'SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()',
    ))->pluck('t')->all();

    $fantasmas = array_values(array_diff(listaDePurga(), $existentes));

    expect($fantasmas)->toBe([], sprintf(
        'La lista de purga nombra tablas que no existen: %s',
        implode(', ', $fantasmas),
    ));
});
