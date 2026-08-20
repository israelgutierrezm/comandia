<?php

declare(strict_types=1);

namespace App\Modules\Pos\Http\Resources;

use App\Modules\Identity\Application\MembershipNameResolver;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Pos\Infrastructure\Models\PosTicket;
use App\Modules\Pos\Infrastructure\Models\PosTicketItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PosTicket
 */
final class PosTicketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),

            // Sólo el ticket final folia (§3.6 del diseño), así que aquí viene `null` para todo lo demás. Publicarlo
            // igual —en lugar de omitir la llave— evita que el cliente tenga que saber qué tipos folian.
            'folio' => $this->folioNumber(),

            'account' => $this->whenLoaded('account', fn () => [
                'ulid' => $this->account->ulid,
                'display_name' => $this->account->displayName(),
            ]),

            'order_sequence' => $this->whenLoaded('order', fn () => $this->order?->sequence),

            'preparation_area' => $this->whenLoaded('preparationArea', fn () => $this->preparationArea === null ? null : [
                'ulid' => $this->preparationArea->ulid,
                'name' => $this->preparationArea->name,
                'code' => $this->preparationArea->code,
            ]),

            'issued_by' => $this->whenLoaded('issuedBy', fn () => $this->person($this->issuedBy)),
            'issued_at' => $this->issued_at?->toIso8601String(),

            // Cuántas veces salió este papel. Es dato de operación —«ya lo imprimí tres veces, el problema es la
            // impresora»— y el detalle de quién lo reimprimió vive en la bitácora, no aquí.
            'reprint_count' => $this->reprint_count,

            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn (PosTicketItem $i): array => [
                'quantity' => $i->quantity,

                // El nombre CONGELADO de la línea, no el del catálogo hoy: una comanda reimpresa tiene que decir lo que
                // decía la original.
                'article_name' => $i->item?->article_name,
                'item_ulid' => $i->item?->ulid,
            ])->all()),
        ];
    }

    /**
     * @return array<string, string|null>|null
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
