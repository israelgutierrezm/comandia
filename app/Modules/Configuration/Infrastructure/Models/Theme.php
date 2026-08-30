<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Infrastructure\Models;

use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un tema visual del negocio: una paleta completa con nombre (Océano, Medianoche, Alto contraste…).
 *
 * Los colores no viven aquí sino en `theme_tokens`, una fila por token (sin JSON). El front recibe los tokens ya
 * resueltos por `ThemeResolver` y los inyecta como CSS custom properties.
 */
final class Theme extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'themes';

    protected $fillable = ['key', 'name', 'is_default', 'allows_user_override'];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'allows_user_override' => 'boolean',
        ];
    }

    /**
     * @return HasMany<ThemeToken, $this>
     */
    public function tokens(): HasMany
    {
        return $this->hasMany(ThemeToken::class, 'theme_id');
    }
}
