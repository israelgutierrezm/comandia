<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\Inventory\Domain\Enums\LotStatus;
use App\Modules\Inventory\Infrastructure\Models\ArticleLot;
use App\Modules\Shared\Domain\Contracts\LotExpiryProbe;

/**
 * Implementación de {@see LotExpiryProbe}: el lote existe y está marcado «Caducado» o tiene la caducidad vencida (D377).
 *
 * «Hoy» es el del negocio —el día más atrasado de sus sucursales—: la sonda no sabe a qué almacén va la recepción, y así
 * nunca se rechaza una partida que todavía vale en alguna sucursal.
 */
final readonly class InventoryLotExpiryProbe implements LotExpiryProbe
{
    public function __construct(private LotCalendar $calendar) {}

    public function isExpired(int $articleId, string $code): bool
    {
        // La misma búsqueda con la que la recepción reusa el lote (`RegisterStockFromPurchaseReceipt`): si ésta lo
        // encuentra, la mercancía entraría a él.
        $lote = ArticleLot::query()->where('article_id', $articleId)->where('code', $code)->first();

        return $lote !== null
            && ($lote->status === LotStatus::Expired || $lote->isExpiredOn($this->calendar->todayInBusiness()));
    }
}
