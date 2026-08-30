<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Listeners;

use App\Modules\Configuration\Application\ThemeSeeder;
use App\Modules\Tenancy\Events\TenantProvisioned;

/**
 * Siembra el catálogo de temas al dar de alta un negocio.
 *
 * Lado de dominio del acoplamiento: `Tenancy` anuncia el alta sin saber quién escucha, y `Configuration` decide que le
 * importa. Síncrono y dentro de la transacción del alta (como el sembrado de unidades): un negocio cuyo propietario
 * entra antes de que la cola procese los temas se encontraría el panel sin apariencia resuelta.
 */
final readonly class SeedThemesForNewTenant
{
    public function __construct(private ThemeSeeder $seeder) {}

    public function handle(TenantProvisioned $event): void
    {
        // `ProvisionTenant` emite dentro de `runFor`: el contexto del tenant nuevo ya está puesto y el scope hace el resto.
        $this->seeder->seed();
    }
}
