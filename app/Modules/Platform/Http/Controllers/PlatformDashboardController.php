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

        // Las altas más recientes, para que el operador vea de un vistazo qué se ha creado últimamente.
        $recent = Tenant::query()
            ->orderByDesc('id')
            ->limit(6)
            ->get(['ulid', 'name', 'status', 'created_at'])
            ->map(fn (Tenant $tenant): array => [
                'ulid' => $tenant->ulid,
                'name' => $tenant->name,
                'status' => $tenant->status->value,
                'status_label' => $tenant->status->label(),
                'created_at' => $tenant->created_at?->toDateString(),
            ]);

        return Inertia::render('Platform/Dashboard', [
            'total' => array_sum($counts),
            'by_status' => $byStatus,
            'recent' => $recent,
        ]);
    }
}
