<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Listeners;

use App\Modules\Catalog\Application\SeedDefaultUnits;
use App\Modules\Tenancy\Events\TenantProvisioned;

/**
 * Siembra las unidades de medida al dar de alta un negocio.
 *
 * Es el lado de dominio del acoplamiento: `Tenancy` —kernel— anuncia el alta sin saber quién
 * escucha, y `Catalog` decide que le importa. Al revés no sería posible: el kernel no puede depender
 * de un módulo de dominio (§2, regla 1) y hay un candado que lo impide.
 *
 * **Síncrono a propósito**, no en cola: sembrar las unidades es parte del alta, y un negocio cuyo
 * propietario entra antes de que la cola procese el sembrado se encuentra con un catálogo en el que no
 * puede capturar nada. El evento se emite dentro de la transacción del alta, así que un fallo aquí
 * revierte el alta completa — que es lo correcto: un tenant sin unidades es un tenant inservible.
 */
final readonly class SeedDefaultUnitsForNewTenant
{
    public function __construct(private SeedDefaultUnits $seeder) {}

    public function handle(TenantProvisioned $event): void
    {
        // No hace falta abrir contexto: `ProvisionTenant` emite el evento dentro de `runFor`, así que
        // el contexto del tenant nuevo ya está puesto y el scope global hace el resto.
        $this->seeder->seed();
    }
}
