<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Identity\Application\MembershipNameResolver;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Inventory\Infrastructure\Models\ProductionOrder;
use App\Modules\Shared\Domain\Support\Decimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProductionOrder
 *
 * Una orden de producción.
 *
 * Un borrador no tiene renglones —se congelan al completar (§migración)— así que en su lugar viaja `preview`: lo que
 * consumiría con la receta de hoy. La UI usa uno u otro sin tener que deducir cuál según el estado, porque sólo uno de
 * los dos viene con contenido.
 */
final class ProductionOrderResource extends JsonResource
{
    /**
     * Consumos previstos, inyectados por el controlador cuando la orden está en borrador.
     *
     * @var list<array<string, mixed>>|null
     */
    public ?array $preview = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_open' => $this->isOpen(),

            'article' => $this->whenLoaded('article', fn () => [
                'ulid' => $this->article->ulid,
                'name' => $this->article->name,
                'base_unit_code' => $this->article->baseUnit?->code,
            ]),

            'warehouse' => $this->whenLoaded('warehouse', fn () => [
                'ulid' => $this->warehouse->ulid,
                'code' => $this->warehouse->code,
                'name' => $this->warehouse->name,
            ]),

            // Las dos cantidades: la que se planeó y la que salió. `null` en la segunda es «todavía no se produjo»,
            // que no es cero.
            'planned_quantity' => $this->planned_quantity,
            'produced_quantity' => $this->produced_quantity,

            'unit_cost_at_production' => $this->unit_cost_at_production,

            // El valor de lo producido, congelado con el costo del momento. Se calcula aquí y no en el cliente por lo
            // mismo que el resto de las multiplicaciones de dinero: es donde se cuela el error de redondeo (D134).
            'total_cost' => $this->produced_quantity !== null && $this->unit_cost_at_production !== null
                ? Decimal::round(bcmul($this->produced_quantity, $this->unit_cost_at_production, 6), 2)
                : null,

            'created_by' => $this->whenLoaded('createdBy', fn () => $this->person($this->createdBy)),
            'produced_by' => $this->whenLoaded('producedBy', fn () => $this->person($this->producedBy)),

            'produced_at' => $this->produced_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),

            'notes' => $this->notes,

            // Lo que de verdad se consumió, con el snapshot de la receta usada. Vacío mientras es borrador.
            'lines' => ProductionOrderLineResource::collection($this->whenLoaded('lines')),

            // Lo que consumiría hoy. Sólo en los borradores.
            'preview' => $this->preview,
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
