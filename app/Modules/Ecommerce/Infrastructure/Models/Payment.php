<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Infrastructure\Models;

use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un pago confirmado de un pedido (Iteración 8, Tanda C). **Inmutable**: se crea al aprobarse el pago (webhook) y no se
 * edita; un reembolso sería una fila de reversa.
 */
final class Payment extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'payments';

    // Inmutable: no hay `updated_at`. `created_at`/`confirmed_at` los pone la base.
    public $timestamps = false;

    protected $fillable = [
        'order_id',
        'gateway',
        'gateway_reference',
        'amount',
        'status',
        'confirmed_at',
    ];

    protected function casts(): array
    {
        return ['confirmed_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
