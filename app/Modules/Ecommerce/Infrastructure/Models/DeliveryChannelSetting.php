<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Infrastructure\Models;

use App\Modules\Ecommerce\Domain\Enums\DeliveryChannelType;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Configuración de un canal de marketplace para una sucursal (ADR-015): encender/apagar + credenciales.
 *
 * Espejo de `PaymentGatewaySetting`, pero por (sucursal, canal): cada sucursal es una "tienda" en la
 * plataforma. Los secretos se guardan **cifrados** (reversibles: el adaptador los necesita para llamar a
 * la API) y nunca salen por un Resource. Un canal `is_active=false` o sin credenciales no opera.
 *
 * @property DeliveryChannelType $channel
 */
final class DeliveryChannelSetting extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'delivery_channel_settings';

    protected $fillable = [
        'branch_id',
        'channel',
        'is_active',
        'external_store_id',
        'api_key',
        'api_secret',
        'webhook_secret',
        'commission_rate',
    ];

    /** Los secretos nunca se serializan: se guardan cifrados y no salen por ningún Resource. */
    protected $hidden = ['api_key', 'api_secret', 'webhook_secret'];

    protected function casts(): array
    {
        return [
            'channel' => DeliveryChannelType::class,
            'is_active' => 'boolean',
            'commission_rate' => 'decimal:2',
            'api_key' => 'encrypted',
            'api_secret' => 'encrypted',
            'webhook_secret' => 'encrypted',
        ];
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
