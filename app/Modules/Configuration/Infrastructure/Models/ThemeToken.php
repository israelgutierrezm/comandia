<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Infrastructure\Models;

use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un color de un tema: `acento` = `#006A89`. Una fila por token (relacional, sin JSON).
 */
final class ThemeToken extends DomainModel
{
    protected $table = 'theme_tokens';

    protected $fillable = ['theme_id', 'token', 'value'];

    /**
     * @return BelongsTo<Theme, $this>
     */
    public function theme(): BelongsTo
    {
        return $this->belongsTo(Theme::class, 'theme_id');
    }
}
