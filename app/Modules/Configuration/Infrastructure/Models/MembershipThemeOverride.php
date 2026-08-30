<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Infrastructure\Models;

use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;

/**
 * El ajuste personal de color de una persona sobre su tema: un token por fila.
 *
 * Sólo cuenta si el tema elegido admite overrides; la cascada la resuelve `ThemeResolver`.
 */
final class MembershipThemeOverride extends DomainModel
{
    protected $table = 'membership_theme_overrides';

    protected $fillable = ['membership_id', 'token', 'value'];
}
