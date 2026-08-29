<?php

declare(strict_types=1);

namespace App\Modules\Publishing\Providers;

use App\Modules\Publishing\Application\PublishingArticleCoverProbe;
use App\Modules\Shared\Domain\Contracts\ArticleCoverProbe;
use Illuminate\Support\ServiceProvider;

final class PublishingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // El POS pregunta las portadas del catálogo por esta sonda del kernel; `Publishing` la resuelve. Sobrescribe el
        // null-object que el kernel enlaza por defecto.
        $this->app->bind(ArticleCoverProbe::class, PublishingArticleCoverProbe::class);
    }
}
