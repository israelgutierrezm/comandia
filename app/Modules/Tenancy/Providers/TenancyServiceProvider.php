<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Providers;

use App\Modules\Tenancy\Console\CreateTenantCommand;
use Illuminate\Support\ServiceProvider;

/**
 * Registro del módulo Tenancy.
 */
final class TenancyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([CreateTenantCommand::class]);
        }
    }
}
