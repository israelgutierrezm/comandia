<?php

declare(strict_types=1);

namespace App\Modules\Customers\Infrastructure\Models;

use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El enlace vivo para que un cliente de la tienda cree una contraseña nueva (D381). A lo más uno por cliente.
 *
 * El token sólo existe en claro en el correo; aquí vive su SHA-256, así que una copia de la base no entrega enlaces
 * utilizables. Lo maneja {@see \App\Modules\Customers\Application\CustomerPasswordResets}.
 *
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable $created_at
 */
final class CustomerPasswordReset extends DomainModel
{
    public const UPDATED_AT = null;

    protected $table = 'customer_password_resets';

    protected $fillable = [
        'customer_id',
        'email',
        'token_hash',
        'expires_at',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
