<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Infrastructure\Models;

use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;

/**
 * La configuración SMTP de un negocio (Tanda D1). Una por tenant.
 *
 * La contraseña se cifra en reposo (cast `encrypted`) y jamás se expone por la API.
 */
final class TenantMailSetting extends DomainModel
{
    protected $table = 'tenant_mail_settings';

    protected $fillable = [
        'host',
        'port',
        'encryption',
        'username',
        'password',
        'from_address',
        'from_name',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'port' => 'integer',
            'verified_at' => 'immutable_datetime',
        ];
    }
}
