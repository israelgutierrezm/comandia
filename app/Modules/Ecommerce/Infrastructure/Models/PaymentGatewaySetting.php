<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Infrastructure\Models;

use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;

/**
 * La pasarela de pago de un negocio (Iteración 8, Tanda C, D49). Una activa a la vez. Las claves secretas se cifran en
 * reposo y jamás vuelven por la API.
 */
final class PaymentGatewaySetting extends DomainModel
{
    protected $table = 'payment_gateway_settings';

    protected $fillable = [
        'active_gateway',
        'public_key',
        'secret_key',
        'webhook_secret',
    ];

    protected $hidden = ['secret_key', 'webhook_secret'];

    protected function casts(): array
    {
        return [
            'secret_key' => 'encrypted',
            'webhook_secret' => 'encrypted',
        ];
    }
}
