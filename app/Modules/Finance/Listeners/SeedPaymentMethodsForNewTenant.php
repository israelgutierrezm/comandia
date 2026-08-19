<?php

declare(strict_types=1);

namespace App\Modules\Finance\Listeners;

use App\Modules\Finance\Application\SeedSystemPaymentMethods;
use App\Modules\Tenancy\Events\TenantProvisioned;

/**
 * Siembra los métodos de pago al dar de alta un negocio.
 *
 * Mismo patrón que las unidades de medida del catálogo: `Tenancy` —kernel— anuncia el alta sin saber quién escucha, y
 * `Finance` decide que le importa. Al revés no sería posible, porque el kernel no puede depender de un módulo de
 * dominio (§2, regla 1) y hay un candado que lo impide.
 *
 * **Síncrono a propósito**, no en cola: un negocio cuyo cajero abre caja antes de que la cola procese el sembrado no
 * puede cobrar nada. El evento se emite dentro de la transacción del alta, así que un fallo aquí revierte el alta
 * completa — que es lo correcto: un negocio sin métodos de pago es un negocio que no vende.
 */
final readonly class SeedPaymentMethodsForNewTenant
{
    public function __construct(private SeedSystemPaymentMethods $seeder) {}

    public function handle(TenantProvisioned $event): void
    {
        // No hace falta abrir contexto: `ProvisionTenant` emite el evento dentro de `runFor`, así que el contexto del
        // negocio nuevo ya está puesto y el scope global hace el resto.
        $this->seeder->seed();
    }
}
