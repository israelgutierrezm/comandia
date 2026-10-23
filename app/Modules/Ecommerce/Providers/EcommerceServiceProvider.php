<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Providers;

use App\Modules\Ecommerce\Console\ExpireUnpaidOrdersCommand;
use Illuminate\Support\ServiceProvider;

/**
 * Proveedor del módulo `Ecommerce`.
 *
 * Hasta ahora el módulo no lo necesitaba: sus rutas y migraciones las engancha `ModuleServiceProvider` por convención.
 * Nace para registrar su primera tarea de consola, la que vence los pedidos que nadie pagó (D380).
 */
final class EcommerceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ExpireUnpaidOrdersCommand::class]);
        }
    }
}
