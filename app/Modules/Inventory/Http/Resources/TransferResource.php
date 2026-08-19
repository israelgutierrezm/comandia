<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Identity\Application\MembershipNameResolver;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Inventory\Infrastructure\Models\Transfer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Transfer
 *
 * Una transferencia con sus cinco sellos.
 *
 * Los pasos omitidos por configuración viajan con sello **nulo**, y eso es información: dice «este paso no se pidió»,
 * que es distinto de «está pendiente». Lo segundo lo dice el estado.
 */
final class TransferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,

            // El folio como lo lee una persona, y sus partes por separado para quien filtre.
            'folio' => $this->folioNumber(),
            'series' => $this->series,
            'folio_number' => $this->folio,

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_open' => $this->status->isOpen(),
            'has_shipped' => $this->status->hasShipped(),

            // Los estados a los que puede pasar, calculados en el servidor. Es la lección de D139: si el cliente
            // los deduce, acaba con su propia copia de la máquina de estados y se desincroniza en la primera
            // iteración que añada un paso.
            'allowed_next' => array_map(
                fn ($status): string => $status->value,
                $this->status->allowedNext(),
            ),

            'origin_warehouse' => $this->whenLoaded('originWarehouse', fn () => [
                'ulid' => $this->originWarehouse->ulid,
                'code' => $this->originWarehouse->code,
                'name' => $this->originWarehouse->name,
            ]),

            'destination_warehouse' => $this->whenLoaded('destinationWarehouse', fn () => [
                'ulid' => $this->destinationWarehouse->ulid,
                'code' => $this->destinationWarehouse->code,
                'name' => $this->destinationWarehouse->name,
            ]),

            'steps' => [
                'requested' => $this->step($this->whenLoaded('requestedBy', fn () => $this->requestedBy), $this->requested_at),
                'authorized' => $this->step($this->whenLoaded('authorizedBy', fn () => $this->authorizedBy), $this->authorized_at),
                'prepared' => $this->step($this->whenLoaded('preparedBy', fn () => $this->preparedBy), $this->prepared_at),
                'shipped' => $this->step($this->whenLoaded('shippedBy', fn () => $this->shippedBy), $this->shipped_at),
                'received' => $this->step($this->whenLoaded('receivedBy', fn () => $this->receivedBy), $this->received_at),
            ],

            'notes' => $this->notes,

            'lines' => TransferLineResource::collection($this->whenLoaded('lines')),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function step(mixed $membership, mixed $at): ?array
    {
        if ($at === null) {
            return null;
        }

        return [
            'at' => $at->toIso8601String(),
            'by' => $membership instanceof TenantMembership ? [
                'ulid' => $membership->ulid,
                'name' => app(MembershipNameResolver::class)->resolve($membership)->short(),
                'employee_code' => $membership->employee_code,
            ] : null,
        ];
    }
}
