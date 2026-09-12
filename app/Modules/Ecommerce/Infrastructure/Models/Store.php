<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Infrastructure\Models;

use App\Modules\Ecommerce\Domain\Enums\StoreFulfillmentMode;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * La tienda en línea de un negocio (Iteración 8, Tanda B). Una por tenant: sirve las sucursales que atiende y el cliente
 * elige una al comprar (D48). El `slug` es único globalmente porque `/t/{slug}` resuelve el negocio sin sesión.
 *
 * El `fulfillment_mode` y las opciones de entrega (ADR-013) deciden cómo opera: preparación (A&B) o envío (retail), y
 * qué entregas ofrece.
 *
 * @property StoreFulfillmentMode $fulfillment_mode
 * @property bool $offers_pickup
 * @property bool $offers_shipping
 */
final class Store extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'stores';

    protected $fillable = [
        'slug',
        'name',
        'is_active',
        'theme_primary',
        'auto_accept_orders',
        'fulfillment_mode',
        'offers_pickup',
        'offers_shipping',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'auto_accept_orders' => 'boolean',
            'fulfillment_mode' => StoreFulfillmentMode::class,
            'offers_pickup' => 'boolean',
            'offers_shipping' => 'boolean',
        ];
    }

    /** Modo envío (retail): el pedido no genera comanda y el fulfillment es empacar → enviar. */
    public function isDispatch(): bool
    {
        return $this->fulfillment_mode->isDispatch();
    }

    /**
     * @return HasMany<StoreBranch, $this>
     */
    public function storeBranches(): HasMany
    {
        return $this->hasMany(StoreBranch::class);
    }
}
