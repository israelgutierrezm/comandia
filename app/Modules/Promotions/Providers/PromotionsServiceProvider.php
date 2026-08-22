<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Providers;

use App\Modules\Promotions\Application\PromotionEngine;
use App\Modules\Promotions\Listeners\RecordPromotionApplication;
use App\Modules\Shared\Domain\Contracts\PromotionResolver;
use App\Modules\Shared\Domain\Events\PosPromotionApplied;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Proveedor del módulo `Promotions`.
 *
 * Lo registra `ModuleServiceProvider` desde el registro declarativo de `config/comandia.php`.
 *
 * ## Dos enganches, uno para decidir y otro para registrar
 *
 * - **Decidir:** liga el contrato del kernel `PromotionResolver` al motor real. El kernel lo tenía ligado a un
 *   null-object; esto lo reemplaza. Como los módulos se registran en el orden del registro y `Shared` (kernel) va antes
 *   que `Promotions` (operations), este binding gana. Si el módulo faltara, el POS seguiría con el null-object y cobraría
 *   sin promoción — nunca se bloquea.
 * - **Registrar:** escucha `PosPromotionApplied` para escribir el registro por venta (§6.3). El POS anuncia que aplicó
 *   una promoción; este módulo la anota. Un evento, no una escritura directa: el registro es un efecto que puede llegar
 *   tarde (D231, D239).
 */
final class PromotionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PromotionResolver::class, PromotionEngine::class);
    }

    public function boot(): void
    {
        // Un oyente sin `Event::listen` no falla: NO corre. El candado de oyentes registrados lo vigila.
        Event::listen(PosPromotionApplied::class, RecordPromotionApplication::class);
    }
}
