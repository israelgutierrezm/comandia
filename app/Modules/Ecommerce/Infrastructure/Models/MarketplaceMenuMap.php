<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Infrastructure\Models;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Ecommerce\Domain\Enums\DeliveryChannelType;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mapeo del menú de un marketplace (ADR-015): id de ítem en la plataforma → artículo de Comandia, por
 * canal. La ingesta lo usa para traducir cada línea del pedido entrante; un ítem sin mapear se rechaza.
 * El admin lo gestiona por API (Fase 2), así que lleva ULID público.
 *
 * @property DeliveryChannelType $channel
 */
final class MarketplaceMenuMap extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'marketplace_menu_maps';

    protected $fillable = ['channel', 'external_item_id', 'article_id'];

    protected function casts(): array
    {
        return ['channel' => DeliveryChannelType::class];
    }

    /**
     * @return BelongsTo<Article, $this>
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }
}
