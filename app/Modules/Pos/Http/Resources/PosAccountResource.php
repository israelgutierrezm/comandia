<?php

declare(strict_types=1);

namespace App\Modules\Pos\Http\Resources;

use App\Modules\Identity\Application\MembershipNameResolver;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Pos\Domain\Enums\PosAccountStatus;
use App\Modules\Pos\Infrastructure\Models\PosAccount;
use App\Modules\Pos\Infrastructure\Models\PosOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PosAccount
 */
final class PosAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'folio' => $this->folioNumber(),

            // Cómo se identifica ante quien la mira: la mesa, el número de mostrador, la etiqueta libre o el folio. Lo
            // resuelve el SERVIDOR para que la pantalla de piso, el ticket y la comanda digan lo mismo (D139).
            'display_name' => $this->displayName(),

            'kind' => $this->kind,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_open' => $this->isOpen(),
            'accepts_items' => $this->status->acceptsItems(),

            'allowed_next' => array_map(
                fn (PosAccountStatus $s): string => $s->value,
                $this->status->allowedNext(),
            ),

            // El candado optimista (§11). Quien cobra manda la versión que leyó; si no coincide, recibe 409 y vuelve a
            // cargar. Sin publicarla, el cliente no tendría qué mandar.
            'version' => $this->version,

            'label' => $this->label,
            'takeout_number' => $this->takeout_number,

            // La llave de la respuesta sigue siendo `table`, que es lo que el cliente entiende. Lo que cambió es el
            // nombre de la RELACIÓN, porque `table` choca con la propiedad `$table` de Eloquent.
            'table' => $this->whenLoaded('restaurantTable', fn () => $this->restaurantTable === null ? null : [
                'ulid' => $this->restaurantTable->ulid,
                'code' => $this->restaurantTable->code,
                'seats' => $this->restaurantTable->seats,
            ]),

            'waiter' => $this->whenLoaded('waiter', fn () => $this->person($this->waiter)),
            'opened_by' => $this->whenLoaded('openedBy', fn () => $this->person($this->openedBy)),

            'totals' => [
                'subtotal' => $this->subtotal,
                'discount_total' => $this->discount_total,

                // El IVA va aparte y NO se suma al total: los precios son IVA incluido (D30), así que el impuesto está
                // CONTENIDO en el total. Publicarlo como si se sumara es el error más fácil de cometer al pintar un
                // ticket.
                'vat_total' => $this->vat_total,
                'total' => $this->total,
                'paid_total' => $this->paid_total,
                'tip_total' => $this->tip_total,
                'change_total' => $this->change_total,

                // Lo que falta por cobrar, resuelto en el servidor: es una resta de dinero, y hacerla en el cliente es
                // donde se cuelan los errores de redondeo (D134).
                'due' => bcsub((string) $this->total, (string) $this->paid_total, 2),
            ],

            'orders' => $this->whenLoaded('orders', fn () => $this->orders->map(fn (PosOrder $o): array => [
                'ulid' => $o->ulid,
                'sequence' => $o->sequence,
                'sent_at' => $o->sent_at?->toIso8601String(),
            ])->all()),

            'items' => PosOrderItemResource::collection($this->whenLoaded('items')),

            // Cómo se pagó. Va con la cuenta porque es lo que el ticket final desglosa y lo que el cajero mira para
            // saber qué entregar.
            // Los descuentos, con las DOS personas. Se publican porque el ticket los desglosa y porque la pantalla de
            // la cuenta tiene que poder mostrar quién autorizó qué — es la mitad de para qué sirve registrarlo.
            'discounts' => $this->whenLoaded('discounts', fn () => $this->discounts->map(fn ($d): array => [
                'ulid' => $d->ulid,
                'kind' => $d->kind->value,
                'kind_label' => $d->kind->label(),
                'value' => $d->value,
                'resulting_amount' => $d->resulting_amount,
                'reason' => $d->reason,
                'is_account_wide' => $d->isAccountWide(),
                'applied_by' => $this->person($d->appliedBy),
                'authorized_by' => $this->person($d->authorizedBy),
                'created_at' => $d->created_at?->toIso8601String(),
            ])->all()),

            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn ($p): array => [
                'ulid' => $p->ulid,
                'method' => $p->method?->name,
                'amount' => $p->amount,
                'tendered_amount' => $p->tendered_amount,
                'change_amount' => $p->change_amount,
                'tip_amount' => $p->tip_amount,
                'tip_to' => $this->person($p->tipTo),
                'reference' => $p->reference,
                'occurred_at' => $p->occurred_at?->toIso8601String(),
            ])->all()),

            'opened_at' => $this->opened_at?->toIso8601String(),
            'bill_requested_at' => $this->bill_requested_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
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
