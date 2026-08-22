<?php

declare(strict_types=1);

namespace App\Modules\Customers\Infrastructure\Models;

use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una dirección del cliente. 0..N por cliente. En columnas mexicanas, sin JSON.
 *
 * La usará el e-commerce para la entrega (pickup/envío por zona); por eso se diseña el domicilio completo aunque v1 sólo
 * lo capture.
 */
final class CustomerAddress extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'customer_addresses';

    protected $fillable = [
        'customer_id',
        'label',
        'street',
        'exterior_number',
        'interior_number',
        'neighborhood',
        'municipality',
        'state',
        'postal_code',
        'country',
        'reference',
        'is_default',
    ];

    protected $attributes = [
        'country' => 'MX',
        'is_default' => false,
    ];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
