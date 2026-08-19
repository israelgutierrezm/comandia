<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Resources;

use App\Modules\Identity\Application\MembershipNameResolver;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Purchasing\Infrastructure\Models\PurchaseReceipt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PurchaseReceipt
 *
 * Una recepción de compra.
 *
 * Los tres totales son `null` mientras es borrador: se calculan al confirmar. La UI los pinta como pendientes en lugar
 * de como ceros, que dirían que la factura no cuesta nada.
 */
final class PurchaseReceiptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,

            'folio' => $this->folioNumber(),
            'series' => $this->series,
            'folio_number' => $this->folio,

            // El folio de SU factura: es lo que permite cuadrar con el proveedor.
            'supplier_document_number' => $this->supplier_document_number,

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_open' => $this->isOpen(),
            'is_confirmed' => $this->isConfirmed(),

            'supplier' => $this->whenLoaded('supplier', fn () => [
                'ulid' => $this->supplier->ulid,
                'code' => $this->supplier->code,
                'name' => $this->supplier->displayName(),
            ]),

            'warehouse' => $this->whenLoaded('warehouse', fn () => [
                'ulid' => $this->warehouse->ulid,
                'code' => $this->warehouse->code,
                'name' => $this->warehouse->name,
            ]),

            'received_at' => $this->received_at?->toDateString(),

            // `null` en borrador: se calculan al confirmar.
            'subtotal' => $this->subtotal,
            'tax_total' => $this->tax_total,
            'total' => $this->total,

            // El criterio con el que se confirmó, congelado. Viaja porque explica el costo: sin él, alguien que mire una
            // recepción vieja no sabría si el IVA entró al costo o no (D206).
            'vat_was_creditable' => $this->vat_was_creditable,

            // La reversa, en los dos sentidos. `reverses` dice «ésta deshace aquélla» y `reversed_by` dice «a ésta ya la
            // deshicieron» — sin la segunda, habría que consultar aparte para saber si una recepción sigue en pie.
            'reverses' => $this->whenLoaded('reverses', fn () => $this->reverses === null ? null : [
                'ulid' => $this->reverses->ulid,
                'folio' => $this->reverses->folioNumber(),
            ]),

            'reversed_by' => $this->whenLoaded('reversal', fn () => $this->reversal === null ? null : [
                'ulid' => $this->reversal->ulid,
                'folio' => $this->reversal->folioNumber(),
            ]),

            'is_reversal' => $this->isReversal(),

            'created_by' => $this->whenLoaded('createdBy', fn () => $this->person($this->createdBy)),
            'confirmed_by' => $this->whenLoaded('confirmedBy', fn () => $this->person($this->confirmedBy)),

            'confirmed_at' => $this->confirmed_at?->toIso8601String(),

            'notes' => $this->notes,

            'lines' => PurchaseReceiptLineResource::collection($this->whenLoaded('lines')),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function person(mixed $membership): ?array
    {
        if (! $membership instanceof TenantMembership) {
            return null;
        }

        return [
            'ulid' => $membership->ulid,
            'name' => app(MembershipNameResolver::class)->resolve($membership)->short(),
            'employee_code' => $membership->employee_code,
        ];
    }
}
