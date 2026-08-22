<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Infrastructure\Models;

use App\Modules\Organization\Infrastructure\Models\Branch;
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

    /**
     * La sucursal, para exponer su ULID —nunca el id interno (D3)— cuando la promoción vuelve al cliente para editarla.
     *
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
