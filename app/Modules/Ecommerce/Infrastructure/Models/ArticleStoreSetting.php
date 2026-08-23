<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Infrastructure\Models;

use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;

/**
 * Ajustes de tienda de un artículo (Iteración 8, Tanda B): lo EXCLUSIVO de la tienda que ADR-007 no pone en `Publishing`.
 *
 * - `stock_policy`: cómo respeta el stock la tienda —`sell_always` (nunca se bloquea), `hide` (se oculta si no hay), o
 *   `mark_out_of_stock` (se muestra «agotado»)—.
 * - `channel_price`: precio propio de e-commerce; si está, gana sobre el de sucursal (override por canal). Si es null,
 *   hereda el precio del Core.
 * - `is_in_store`: si el artículo aparece en la tienda.
 */
final class ArticleStoreSetting extends DomainModel
{
    use HasPublicUlid;

    public const POLICIES = ['sell_always', 'hide', 'mark_out_of_stock'];

    protected $table = 'article_store_settings';

    protected $fillable = [
        'article_id',
        'stock_policy',
        'is_in_store',
        'seo_title',
        'seo_description',
        'channel_price',
    ];

    protected function casts(): array
    {
        return [
            'is_in_store' => 'boolean',
            // `channel_price` SIN cast a float: es dinero (DECIMAL 12,2) y entra en aritmética exacta.
        ];
    }
}
