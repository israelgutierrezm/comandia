<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Resources;

use App\Modules\Organization\Infrastructure\Models\Printer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Printer
 */
final class PrinterResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'code' => $this->code,
            'name' => $this->name,

            'connection' => $this->connection->value,
            'connection_label' => $this->connection->label(),
            'target' => $this->target,

            'paper_width' => $this->paper_width,
            'supports_cash_drawer' => $this->supports_cash_drawer,

            'status' => $this->status->value,

            // La conjunción resuelta en el servidor, no en el cliente. Es la lección de D139: si la interfaz calcula
            // «tiene el conector Y está activa», acaba con su propia copia de la regla y se desincroniza en cuanto se
            // agregue una tercera condición.
            'can_open_cash_drawer' => $this->canOpenCashDrawer(),

            'branch' => $this->whenLoaded('branch', fn () => [
                'ulid' => $this->branch->ulid,
                'name' => $this->branch->name,
            ]),

            // Cuántos destinos dependen de esta impresora. Es lo que alguien necesita saber ANTES de darla de baja:
            // «esta imprime las comandas de tres áreas» cambia la decisión.
            'assignments' => [
                'preparation_areas' => $this->whenCounted('preparationAreas'),
                'terminals' => $this->whenCounted('terminals'),
            ],

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
