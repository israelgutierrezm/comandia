<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

/**
 * El precio y la disponibilidad que de verdad aplican en una sucursal.
 *
 * La cascada resuelta, en un objeto: qué valor gana y **de dónde salió**. Lo segundo importa tanto como lo
 * primero — una pantalla que muestra $85 sin decir si es el precio del negocio o el propio de la sucursal
 * obliga a adivinar, y la diferencia decide si editar aquí o allá.
 *
 * Es la misma distinción que la configuración jerárquica hace entre "hereda" y "configurado aquí", y por el
 * mismo motivo: el día que cambie el dato maestro, lo que hereda lo sigue y lo que tiene override no.
 */
final readonly class EffectivePricing
{
    /**
     * @param  numeric-string|null  $price  `null` si el artículo no tiene precio en ningún nivel
     */
    public function __construct(
        public ?string $price,
        public bool $priceIsOverridden,
        public bool $isAvailableInPos,
        public bool $availabilityIsOverridden,
    ) {}

    /**
     * Resuelve la cascada de dos niveles: override de sucursal → dato maestro.
     *
     * Recibe los valores y no los modelos a propósito: así es dominio puro y se puede probar con los cuatro
     * casos de la cascada sin tocar la base. El llamador es quien decide cómo obtuvo el override —una
     * consulta, una relación precargada, un mapa en memoria— y ésa es justo la parte que cambia entre el
     * detalle de un artículo y el catálogo completo del POS.
     *
     * @param  numeric-string|null  $masterPrice
     * @param  numeric-string|null  $overridePrice  `null` = hereda
     * @param  bool|null  $overrideAvailability  `null` = hereda
     */
    public static function resolve(
        ?string $masterPrice,
        bool $masterAvailability,
        ?string $overridePrice,
        ?bool $overrideAvailability,
    ): self {
        return new self(
            price: $overridePrice ?? $masterPrice,
            priceIsOverridden: $overridePrice !== null,
            isAvailableInPos: $overrideAvailability ?? $masterAvailability,
            availabilityIsOverridden: $overrideAvailability !== null,
        );
    }
}
