<?php

declare(strict_types=1);

namespace App\Modules\Finance\Listeners;

use App\Modules\Finance\Application\SeedSystemExpenseCategories;
use App\Modules\Finance\Application\SeedSystemPaymentMethods;
use App\Modules\Tenancy\Events\TenantProvisioned;

/**
 * Siembra lo mínimo con lo que un negocio puede operar financieramente: métodos de pago y categorías de gasto.
 *
 * Se llamaba `SeedPaymentMethodsForNewTenant` y dejó de ser cierto en cuanto sembró también las categorías. Un nombre
 * que describe la mitad de lo que hace un oyente es peor que uno genérico: el día que alguien busque «dónde se siembran
 * las categorías» no lo va a encontrar aquí.
 *
 * Mismo patrón que las unidades de medida del catálogo: `Tenancy` —kernel— anuncia el alta sin saber quién escucha, y
 * `Finance` decide que le importa. Al revés no sería posible, porque el kernel no puede depender de un módulo de
 * dominio (§2, regla 1) y hay un candado que lo impide.
 *
 * **Síncrono a propósito**, no en cola: un negocio cuyo cajero abre caja antes de que la cola procese el sembrado no
 * puede cobrar nada. El evento se emite dentro de la transacción del alta, así que un fallo aquí revierte el alta
 * completa — que es lo correcto: un negocio sin métodos de pago es un negocio que no vende.
 */
final readonly class SeedFinanceDefaultsForNewTenant
{
    public function __construct(
        private SeedSystemPaymentMethods $methods,
        private SeedSystemExpenseCategories $expenseCategories,
    ) {}

    public function handle(TenantProvisioned $event): void
    {
        // No hace falta abrir contexto: `ProvisionTenant` emite el evento dentro de `runFor`, así que el contexto del
        // negocio nuevo ya está puesto y el scope global hace el resto.
        $this->methods->seed();

        // Las categorías de gasto van en el MISMO oyente y no en otro, a propósito: son el vocabulario financiero
        // mínimo con el que un negocio arranca, y separarlas en dos oyentes daría dos sitios donde olvidarse de
        // registrar uno — que es exactamente el defecto que el candado de oyentes registrados existe para cazar.
        $this->expenseCategories->seed();
    }
}
