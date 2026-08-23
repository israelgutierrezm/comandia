<?php

declare(strict_types=1);

namespace App\Modules\DigitalMenus\Infrastructure\Models;

use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un menú digital por sucursal (Iteración 8, Tanda A). Se sirve en `/m/{slug}` y se genera en PDF.
 *
 * El `slug` es único globalmente porque la ruta pública no tiene sesión: es el slug quien resuelve el negocio.
 */
final class DigitalMenu extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'digital_menus';

    protected $fillable = [
        'branch_id',
        'slug',
        'is_active',
        'show_prices',
        'theme_primary',
        'theme_logo_path',
        'theme_font',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'show_prices' => 'boolean',
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
