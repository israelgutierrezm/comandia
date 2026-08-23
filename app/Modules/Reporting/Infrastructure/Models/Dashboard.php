<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Models;

use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un tablero, del autor. Si `published_role_id` está puesto, lo ven quienes tengan ese rol activo (D46).
 */
final class Dashboard extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'dashboards';

    protected $fillable = [
        'membership_id',
        'name',
        'published_role_id',
    ];

    /**
     * @return HasMany<DashboardWidget, $this>
     */
    public function widgets(): HasMany
    {
        return $this->hasMany(DashboardWidget::class, 'dashboard_id');
    }
}
