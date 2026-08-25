<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Tenancy\Domain\Enums\TenantStatus;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El tablero de la plataforma: cuántos negocios hay y en qué estado. Sin datos de dominio de ningún negocio — sólo el
 * conteo del registro de negocios.
 */
final class PlatformDashboardController
{
    public function show(): Response
    {
        /** @var array<string, int> $counts */
        $counts = Tenant::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $byStatus = [];

        foreach (TenantStatus::cases() as $status) {
            $byStatus[] = [
                'status' => $status->value,
                'label' => $status->label(),
                'total' => (int) ($counts[$status->value] ?? 0),
            ];
        }

        return Inertia::render('Platform/Dashboard', [
            'total' => array_sum($counts),
            'by_status' => $byStatus,
        ]);
    }
}
