<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Sembrado base de la aplicación.
 *
 * Sólo siembra lo que es **del sistema** y por tanto igual en toda instalación: el
 * catálogo cerrado de permisos.
 *
 * Deliberadamente NO crea tenants, usuarios ni datos de demostración. Los roles
 * plantilla de un tenant los crea `ProvisionTenantRoles` al darlo de alta, no el
 * despliegue; y el tenant de demostración para QA y demos comerciales
 * (ARQUITECTURA_MAESTRA §11) llega con datos de negocio que todavía no existen.
 *
 * Es idempotente: se puede correr en cada despliegue.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PermissionCatalogSeeder::class);
    }
}
