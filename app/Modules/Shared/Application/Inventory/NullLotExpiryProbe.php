<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Inventory;

use App\Modules\Shared\Domain\Contracts\LotExpiryProbe;

/**
 * Null-object de {@see LotExpiryProbe}: sin `Inventory` no hay lotes, así que ninguno está caducado y la recepción sigue.
 */
final class NullLotExpiryProbe implements LotExpiryProbe
{
    public function isExpired(int $articleId, string $code): bool
    {
        return false;
    }
}
