<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Infrastructure\Models;

use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;

/**
 * Una zona de envío de la tienda (Iteración 8, Tanda C): nombre + costo, que suma al total del pedido. La cobertura por
 * código postal es evolución.
 */
final class ShippingZone extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'shipping_zones';

    protected $fillable = [
        'store_id',
        'name',
        'cost',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            // `cost` es DECIMAL(12,2): sin cast a float.
        ];
    }
}
