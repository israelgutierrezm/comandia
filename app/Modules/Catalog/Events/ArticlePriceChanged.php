<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Events;

use App\Modules\Catalog\Infrastructure\Models\PriceChange;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Cambió el precio de un artículo (D15).
 *
 * Lo consumirán los módulos activables de la Iteración 9 —menú QR y tienda en línea— para invalidar su cache
 * pública: un precio viejo en un menú público es una promesa que el POS no va a cumplir.
 *
 * Hoy nadie lo escucha, y eso es correcto. La alternativa es agregarlo después, cuando ya haya precios
 * cambiando sin avisar y nadie recuerde por qué el menú público muestra otro número.
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
