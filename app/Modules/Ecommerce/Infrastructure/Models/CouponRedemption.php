<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Infrastructure\Models;

use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;

/**
 * El canje de un cupón en un pedido (Iteración 8, Tanda D, D3 parte 2). Se crea al colocar el pedido y no se edita, salvo
 * para DEVOLVERLO: un pedido que termina sin pago (vencido, fallido o cancelado antes de pagar) le devuelve el uso al cupón
 * y al cliente (`released_at`, D380). El renglón se queda —es la historia de que se intentó—, pero deja de contar. Un
 * cupón por pedido (llave única).
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
        'released_at',
    ];

    protected function casts(): array
    {
        return [
            'redeemed_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
        ];
    }
}
