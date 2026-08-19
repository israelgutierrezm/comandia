<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Identity\Application\MembershipNameResolver;
use App\Modules\Inventory\Infrastructure\Models\StockCount;
use App\Modules\Shared\Application\Authorization\Authorize;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StockCount
 *
 * Un conteo físico. Las cifras del cierre están sujetas al mismo conteo ciego que las líneas: mientras el conteo
 * está abierto, un total de diferencias sería la misma pista que la diferencia renglón por renglón.
 */
final class StockCountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $puedeVerDiferencias = app(Authorize::class)->allows('inventory.counts.close');

        return [
            'ulid' => $this->ulid,

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_open' => $this->isOpen(),

            'warehouse' => $this->whenLoaded('warehouse', fn () => [
                'ulid' => $this->warehouse->ulid,
                'code' => $this->warehouse->code,
                'name' => $this->warehouse->name,
            ]),

            'started_by' => $this->whenLoaded('startedBy', fn () => $this->personName($this->startedBy)),
            'closed_by' => $this->whenLoaded('closedBy', fn () => $this->personName($this->closedBy)),

            // Quién autorizó el cierre. `null` cuando la diferencia no pasó el umbral, y NO se rellena con quien
            // cerró: «no hacía falta autorización» y «se autorizó a sí mismo» son cosas distintas (D172).
            'authorized_by' => $this->whenLoaded('authorizedBy', fn () => $this->personName($this->authorizedBy)),

            'started_at' => $this->started_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),

            'notes' => $this->notes,

            // Las cifras del cierre. Un conteo cerrado ya las publica a cualquiera que pueda ver el conteo: el
            // secreto sólo tenía sentido mientras se contaba.
            ...$puedeVerDiferencias || ! $this->isOpen() ? [
                'variance_value' => $this->variance_value,
                'variance_value_absolute' => $this->variance_value_absolute,
            ] : [],

            'lines' => StockCountLineResource::collection($this->whenLoaded('lines')),
        ];
    }

    /**
     * @return array<string, string|null>|null
     */
    private function personName(mixed $membership): ?array
    {
        if ($membership === null) {
            return null;
        }

        return [
            'ulid' => $membership->ulid,
            'name' => app(MembershipNameResolver::class)->resolve($membership)->short(),
            'employee_code' => $membership->employee_code,
        ];
    }
}
