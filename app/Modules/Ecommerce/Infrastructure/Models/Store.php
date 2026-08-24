<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Infrastructure\Models;

use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * La tienda en línea de un negocio (Iteración 8, Tanda B). Una por tenant: sirve las sucursales que atiende y el cliente
 * elige una al comprar (D48). El `slug` es único globalmente porque `/t/{slug}` resuelve el negocio sin sesión.
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
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'auto_accept_orders' => 'boolean',
        ];
    }

    /**
     * @return HasMany<StoreBranch, $this>
     */
    public function storeBranches(): HasMany
    {
        return $this->hasMany(StoreBranch::class);
    }
}
