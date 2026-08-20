<?php

declare(strict_types=1);

namespace App\Modules\Floor\Http\Resources;

use App\Modules\Floor\Infrastructure\Models\RestaurantTable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RestaurantTable
 */
final class RestaurantTableResource extends JsonResource
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
            'seats' => $this->seats,

            'status' => $this->status->value,
            'status_label' => $this->status->label(),

            // Coordenadas LÓGICAS, nunca píxeles (ADR-003). Van en la respuesta desde esta iteración aunque el editor
            // visual llegue en la 6: sin ellas, la 6 tendría que migrar datos de un salón ya en uso.
            'geometry' => [
                'x' => $this->x,
                'y' => $this->y,
                'width' => $this->width,
                'height' => $this->height,
                'rotation' => $this->rotation,
                'shape' => $this->shape,
            ],

            'zone' => $this->whenLoaded('zone', fn () => [
                'ulid' => $this->zone->ulid,
                'name' => $this->zone->name,
            ]),

            // La unión (D32): a qué mesa está unida, y cuáles cuelgan de ella.
            'joined_to' => $this->whenLoaded('joinedTo', fn () => $this->joinedTo === null ? null : [
                'ulid' => $this->joinedTo->ulid,
                'code' => $this->joinedTo->code,
            ]),

            'joined_tables' => $this->whenLoaded(
                'joinedTables',
                fn () => $this->joinedTables->map(fn (RestaurantTable $t): array => [
                    'ulid' => $t->ulid,
                    'code' => $t->code,
                    'seats' => $t->seats,
                ])->all(),
            ),

            // Las dos preguntas que la pantalla de piso hace de verdad, resueltas en el servidor.
            //
            // `is_available` NO es «el estado dice libre»: una mesa unida a otra forma parte de un conjunto que atiende
            // una sola cuenta, así que no se le puede sentar gente por su cuenta aunque figure libre. Y los asientos
            // efectivos cuentan las mesas unidas, que es el dato que alguien necesita al sentar un grupo.
            'is_available' => $this->isAvailable(),
            'effective_seats' => $this->whenLoaded('joinedTables', fn () => $this->effectiveSeats()),
        ];
    }
}
