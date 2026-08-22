<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Resources;

use App\Modules\Shared\Domain\Consumption\ConsumptionEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un consumo del expediente. Envuelve el DTO del kernel —no un modelo—, porque `Customers` nunca ve una cuenta del POS.
 *
 * @mixin ConsumptionEntry
 */
final class ConsumptionEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'account_ulid' => $this->accountUlid,
            'reference' => $this->reference,
            'branch_name' => $this->branchName,

            // La zona viaja para que la ficha muestre la fecha en hora de la sucursal, no del navegador (la regla de
            // siempre: UTC al guardar, zona de la sucursal al presentar).
            'branch_timezone' => $this->branchTimezone,
            'occurred_at' => $this->occurredAt,
            'total' => $this->total,
            'status' => $this->status,
        ];
    }
}
