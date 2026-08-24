<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Infrastructure\Models;

use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;

/**
 * El canje de un cupón en un pedido (Iteración 8, Tanda D, D3 parte 2). **Inmutable**: se crea al colocar el pedido y no se
 * edita. Un cupón por pedido (llave única). Cuenta usos globales y por cliente para hacer cumplir los topes.
 */
final class CouponRedemption extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'coupon_redemptions';

    public $timestamps = false;

    protected $fillable = [
        'coupon_id',
        'order_id',
        'customer_id',
        'amount_discounted',
        'redeemed_at',
    ];

    protected function casts(): array
    {
        return ['redeemed_at' => 'immutable_datetime'];
    }
}
