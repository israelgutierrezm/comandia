<?php

declare(strict_types=1);

namespace App\Modules\Costing\Providers;

use App\Modules\Costing\Console\RebuildCurrentCostsCommand;
use Illuminate\Support\ServiceProvider;

/**
 * Proveedor del módulo `Costing`.
 *
 * Este módulo declara depender de `Catalog` en `config/comandia.php`, y el candado de fronteras lo
 * impone: lee artículos y presentaciones, y **nunca les escribe** (P1 de la Iteración 2). Aceptar un
 * precio sugerido pasará por el servicio de `Catalog`, que es el dueño de `articles.base_price` y del
 * historial de precios.
 */
final class CostingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                RebuildCurrentCostsCommand::class,
            ]);
        }
    }
}
