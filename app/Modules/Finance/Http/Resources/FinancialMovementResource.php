<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Resources;

use App\Modules\Finance\Infrastructure\Models\FinancialMovement;
use App\Modules\Identity\Application\MembershipNameResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FinancialMovement
 *
 * Un asiento del diario. Se lee, nunca se edita: no hay recurso de escritura porque no hay endpoint de escritura — al
 * diario sólo escriben los oyentes de eventos de dominio (ADR-004).
 */
final class FinancialMovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,

            'type' => $this->type->value,
            'type_label' => $this->type->label(),

            'amount' => $this->amount,
            'affects_cash_drawer' => $this->affects_cash_drawer,

            // El documento que lo originó, por ULID (D151): la llave interna no se expone y sólo significaría algo
            // mientras la fila exista.
            'source' => [
                'type' => class_basename($this->source_type),
                'ulid' => $this->source_ulid,
            ],

            'payment_method' => $this->whenLoaded('paymentMethod', fn () => $this->paymentMethod === null ? null : [
                'ulid' => $this->paymentMethod->ulid,
                'code' => $this->paymentMethod->code,
                'name' => $this->paymentMethod->name,
            ]),

            'branch' => $this->whenLoaded('branch', fn () => [
                'ulid' => $this->branch->ulid,
                'name' => $this->branch->name,
            ]),

            'actor' => $this->whenLoaded('actor', fn () => $this->actor === null ? null : [
                'ulid' => $this->actor->ulid,
                'name' => app(MembershipNameResolver::class)->resolve($this->actor)->short(),
                'employee_code' => $this->actor->employee_code,
            ]),

            // El enlace de la corrección. Que exista es lo que distingue «esto se cobró y luego se devolvió» de «esto
            // nunca pasó», que es la única forma honesta de corregir un diario append-only.
            'is_reversal' => $this->isReversal(),
            'reverses' => $this->whenLoaded('reverses', fn () => $this->reverses === null ? null : [
                'ulid' => $this->reverses->ulid,
                'type' => $this->reverses->type->value,
                'amount' => $this->reverses->amount,
            ]),

            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
