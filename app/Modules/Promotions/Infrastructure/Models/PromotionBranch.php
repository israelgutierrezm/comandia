<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Infrastructure\Models;

use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * En qué sucursal aplica una promoción, cuando no es `all_branches`.
 */
final class PromotionBranch extends DomainModel
{
    protected $table = 'promotion_branches';

    protected $fillable = [
        'promotion_id',
        'branch_id',
    ];

    /**
     * @return BelongsTo<Promotion, $this>
     */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }
}
