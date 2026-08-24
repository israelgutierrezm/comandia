<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Infrastructure\Models;

use App\Modules\Ecommerce\Domain\Enums\CouponType;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;

/**
 * Un cupón de la tienda (Iteración 8, Tanda D, D3): un código con un descuento acotado, vigencia y topes de uso. El canje
 * lo cuenta `coupon_redemptions` (parte 2); `uses_count` lo incrementa el canje, no la administración, por eso no es
 * asignable en masa.
 */
final class Coupon extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'coupons';

    protected $fillable = [
        'store_id',
        'code',
        'type',
        'value',
        'valid_from',
        'valid_until',
        'max_uses',
        'per_customer_limit',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'type' => CouponType::class,
            'valid_from' => 'immutable_date',
            'valid_until' => 'immutable_date',
            'max_uses' => 'integer',
            'uses_count' => 'integer',
            'per_customer_limit' => 'integer',
            'is_active' => 'boolean',
            // `value` es DECIMAL(12,2): sin cast a float.
        ];
    }
}
