<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Contracts;

/**
 * «¿Cuál es la portada de estos artículos?»
 *
 * El POS pinta el catálogo con la foto del producto, pero las imágenes viven en `Publishing` (galería por artículo). Para
 * no acoplar el catálogo a `Publishing`, el POS pregunta las portadas por esta sonda del kernel: `Publishing` la implementa
 * devolviendo la 1.ª foto por `sort_order`; el catálogo la consume sin nombrar a `Publishing` (mismo patrón que
 * {@see LiveServiceProbe} y {@see StockAvailabilityProbe}).
 *
 * El null-object devuelve un mapa vacío (sin fotos): si la sonda no se resuelve, el grid pinta cuadros sin foto en vez de
 * fallar.
 */
interface ArticleCoverProbe
{
    /**
     * URL de portada por artículo, EN LOTE (una sola consulta para toda una página del POS, no N). Recibe ids internos —el
     * llamador ya tiene los artículos resueltos— y devuelve primitivos: el catálogo jamás toca un modelo de `Publishing`.
     * Un artículo sin foto simplemente no aparece en el mapa.
     *
     * @param  list<int>  $articleIds
     * @return array<int, string>  articleId => URL
     */
    public function coversFor(array $articleIds): array;
}
