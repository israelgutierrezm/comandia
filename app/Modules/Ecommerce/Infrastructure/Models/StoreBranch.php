<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Infrastructure\Models;

use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una sucursal que la tienda atiende (Iteración 8, Tanda B). Normalizado (no una lista JSON): el cliente elige entre estas
 * sucursales al comprar.
 */
final class StoreBranch extends DomainModel
{
    protected $table = 'store_branches';

    public $timestamps = false;

    protected $fillable = [
        'store_id',
        'branch_id',
    ];

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
