<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Resources;

use App\Modules\Finance\Infrastructure\Models\BankDeposit;
use App\Modules\Identity\Application\MembershipNameResolver;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BankDeposit
 */
final class BankDepositResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'amount' => $this->amount,
            'bank_name' => $this->bank_name,
            'reference' => $this->reference,

            // Fecha sin hora: un depósito se hace en un día, no en un instante. Publicar un timestamp obligaría al
            // cliente a decidir qué hora mostrar de una que nadie capturó.
            'deposited_on' => $this->deposited_on?->toDateString(),

            'branch' => $this->whenLoaded('branch', fn () => [
                'ulid' => $this->branch->ulid,
                'name' => $this->branch->name,
            ]),

            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy instanceof TenantMembership ? [
                'ulid' => $this->createdBy->ulid,
                'name' => app(MembershipNameResolver::class)->resolve($this->createdBy)->short(),
            ] : null),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
