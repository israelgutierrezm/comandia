<?php

declare(strict_types=1);

namespace App\Modules\Floor\Http\Resources;

use App\Modules\Floor\Infrastructure\Models\FloorPlan;
use App\Modules\Floor\Infrastructure\Models\FloorZone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FloorPlan
 */
final class FloorPlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'name' => $this->name,
            'is_default' => $this->is_default,
            'status' => $this->status->value,

            // El lienzo en CENTÍMETROS, que es la unidad lógica de ADR-003 fijada en la Iteración 5. Va al cliente
            // porque es el `viewBox` del SVG: sin él, cada cliente supondría un tamaño y dibujaría el mismo plano
            // distinto.
            'canvas' => [
                'width' => $this->canvas_width,
                'height' => $this->canvas_height,
                'unit' => 'cm',
            ],

            // La versión viaja para que el editor la devuelva al guardar. Sin ella, dos gerentes editando a la vez se
            // pisan sin enterarse y el resultado no es el plano de ninguno de los dos.
            'version' => $this->version,

            'branch' => $this->whenLoaded('branch', fn () => [
                'ulid' => $this->branch->ulid,
                'name' => $this->branch->name,
            ]),

            'zones' => $this->whenLoaded('zones', fn () => $this->zones->map(fn (FloorZone $z): array => [
                'ulid' => $z->ulid,
                'name' => $z->name,
                'sort_order' => $z->sort_order,
            ])->all()),

            // Las mesas sólo cuando se piden: el listado de planos no las quiere, y traerlas ahí serían N+1 consultas
            // por una columna que nadie mira.
            'tables' => RestaurantTableResource::collection($this->whenLoaded('tables')),

            'tables_count' => $this->whenCounted('tables'),
        ];
    }
}
