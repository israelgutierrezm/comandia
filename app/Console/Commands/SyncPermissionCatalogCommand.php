<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Identity\Application\ProvisionTenantRoles;
use App\Modules\Identity\Domain\PermissionCatalog;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;

/**
 * Pone al día el catálogo de permisos y los roles de sistema de TODOS los negocios.
 *
 * ## El defecto que este comando arregla
 *
 * Los permisos del catálogo cerrado (D10) se siembran una vez, y los roles de plantilla se escriben **al dar de alta el
 * negocio**. Así que un permiso agregado en una iteración posterior:
 *
 *   1. **No existe** como fila en `permissions` para una instalación que ya estaba corriendo.
 *   2. Y por tanto ningún rol lo tiene, ni el del propietario.
 *
 * El síntoma es que la ruta protegida por ese permiso devuelve **403 para todo el mundo, para siempre**, sin que nada
 * avise. Es exactamente lo que pasó al verificar la Iteración 3 en el navegador: el botón de «Confirmar recepción»
 * simplemente no aparecía, porque `purchasing.receipts.confirm` —agregado en el paso 9— no existía en la base de un
 * negocio creado antes.
 *
 * Y no lo puede atrapar la suite: cada prueba da de alta un negocio nuevo, con el catálogo del día. El defecto vive
 * exactamente en el hueco entre «se dio de alta con la versión vieja» y «se actualizó el código», que es el estado
 * normal de cualquier instalación real.
 *
 * ## Qué hace, y qué NO toca
 *
 * Siembra los permisos que falten y vuelve a correr `ProvisionTenantRoles` en cada negocio. Ese servicio ya es
 * idempotente y —esto es lo importante— **sólo re-sincroniza los permisos de los roles de SISTEMA**. Los roles de
 * plantilla editables no se tocan, porque reponerlos desharía la configuración que el negocio hizo a conciencia.
 *
 * La consecuencia hay que decirla: un permiso nuevo llega automáticamente al **propietario** (rol de sistema) y **no** a
 * gerente, cajero, mesero ni almacenista, que son editables. En esos, el negocio tiene que agregarlo a mano.
 *
 * Es coherente con la decisión de que esos roles son del negocio, y a la vez significa que la reparte por omisión que
 * este proyecto escribe en `RoleTemplates` no alcanza a un negocio que ya existía. Queda como pregunta abierta para el
 * dueño del producto, no resuelta por este comando.
 */
final class SyncPermissionCatalogCommand extends Command
{
    protected $signature = 'comandia:permissions:sync
        {--dry-run : Sólo informa lo que haría, sin escribir}';

    protected $description = 'Siembra los permisos nuevos del catálogo y re-sincroniza los roles de sistema de cada negocio';

    public function handle(
        TenantContext $context,
        ProvisionTenantRoles $roles,
    ): int {
        $esperados = PermissionCatalog::names();

        $existentes = Permission::query()->where('guard_name', 'web')->pluck('name')->all();

        $faltantes = array_values(array_diff($esperados, $existentes));

        // Los que están en la base y ya no en el catálogo. NO se borran: un permiso retirado puede seguir citado por un
        // rol que el negocio armó, y borrarlo dejaría ese rol sin poder explicar qué permitía. Se informan para que
        // alguien decida.
        $sobrantes = array_values(array_diff($existentes, $esperados));

        $this->line(sprintf('Catálogo: %d permisos. En la base: %d.', count($esperados), count($existentes)));

        if ($faltantes === []) {
            $this->info('No falta ningún permiso.');
        } else {
            $this->warn(sprintf('Faltan %d permisos: %s', count($faltantes), implode(', ', $faltantes)));
        }

        if ($sobrantes !== []) {
            $this->warn(sprintf(
                'Hay %d permisos en la base que ya no están en el catálogo (NO se borran): %s',
                count($sobrantes),
                implode(', ', $sobrantes),
            ));
        }

        if ($this->option('dry-run')) {
            $this->comment('Simulación: no se escribió nada.');

            return self::SUCCESS;
        }

        if ($faltantes !== []) {
            // El sembrador es idempotente y escribe el catálogo completo, así que no hace falta insertar a mano.
            $this->call('db:seed', ['--class' => PermissionCatalogSeeder::class, '--force' => true]);
        }

        $negocios = Tenant::query()->withoutGlobalScopes()->get();

        $this->line(sprintf('Re-sincronizando los roles de sistema de %d negocios…', $negocios->count()));

        foreach ($negocios as $negocio) {
            $context->runFor($negocio->id, function () use ($roles): void {
                $roles->provision();
            });

            $this->line("  · {$negocio->name}");
        }

        $this->info('Listo. Los roles de SISTEMA quedaron al día.');

        $this->comment(
            'Los roles editables —gerente, cajero, mesero, almacenista— NO se tocaron a propósito: reponerlos '
            .'desharía la configuración de cada negocio. Si un permiso nuevo les corresponde, hay que agregarlo desde '
            .'la pantalla de roles.'
        );

        return self::SUCCESS;
    }
}
