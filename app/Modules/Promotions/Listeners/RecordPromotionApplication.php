<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Listeners;

use App\Modules\Promotions\Infrastructure\Models\Promotion;
use App\Modules\Promotions\Infrastructure\Models\PromotionApplication;
use App\Modules\Shared\Domain\Events\PosPromotionApplied;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Database\QueryException;

/**
 * Anota el «registro por venta de promoción aplicada» que §6.3 exige.
 *
 * El POS anuncia que aplicó una promoción; este módulo la registra. Es un evento, no una escritura directa: el registro
 * es analítica —puede llegar tarde—, a diferencia de la DECISIÓN de qué promoción aplica, que va por el probe.
 *
 * Idempotente por el descuento origen: re-despachar el evento no duplica el registro. Y NO puede tumbar el cobro: corre
 * después del commit del pago (D220); un fallo aquí se registra y no se propaga.
 */
final readonly class RecordPromotionApplication
{
    public function __construct(private TenantContext $tenants) {}

    public function handle(PosPromotionApplied $event): void
    {
        $this->tenants->runFor($event->tenantId(), function () use ($event): void {
            $promotion = Promotion::query()->where('ulid', $event->promotionUlid)->first();

            if ($promotion === null) {
                // La definición se borró entre la venta y el registro; no hay nada que anotar y reventar dejaría el job
                // reintentando por algo que ya no existe.
                return;
            }

            try {
                PromotionApplication::create([
                    'promotion_id' => $promotion->id,
                    'pos_account_ulid' => $event->accountUlid,
                    'pos_order_item_ulid' => $event->orderItemUlid,
                    'pos_discount_ulid' => $event->discountUlid,
                    'amount_discounted' => $event->amount,
                    'applied_at' => $event->appliedAt,
                ]);
            } catch (QueryException $e) {
                // Choque con la llave de idempotencia (tenant, pos_discount_ulid): ya estaba registrado. Para quien
                // llama el resultado es el mismo, así que se traga el duplicado en lugar de propagarlo.
                if (! str_contains($e->getMessage(), 'promotion_applications_discount_unique')) {
                    throw $e;
                }
            }
        });
    }
}
