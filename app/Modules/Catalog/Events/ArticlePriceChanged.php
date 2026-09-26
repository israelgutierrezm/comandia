<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Events;

use App\Modules\Catalog\Infrastructure\Models\PriceChange;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Cambió el precio de un artículo (D15).
 *
 * Pensado para que el menú QR y la tienda en línea invaliden una cache pública: un precio viejo en un menú público
 * es una promesa que el POS no va a cumplir. Hoy ninguno de los dos cachea —leen el precio en cada petición—, así
 * que nadie lo escucha, y eso es correcto: el día que se agregue una cache, su invalidación ya tiene de dónde colgar,
 * en vez de descubrir precios cambiando sin avisar.
 *
 * **El historial NO lo escribe un listener**: lo escribe `ChangeArticlePrice` en la misma transacción que el
 * precio. Es historia de dominio propia del catálogo, no un efecto cruzado, y separarla dejaría abierta la
 * posibilidad de un precio cambiado sin historial.
 */
final readonly class ArticlePriceChanged
{
    use Dispatchable;

    public function __construct(public PriceChange $change) {}
}
