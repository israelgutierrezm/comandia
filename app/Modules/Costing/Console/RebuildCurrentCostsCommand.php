<?php

declare(strict_types=1);

namespace App\Modules\Costing\Console;

use App\Modules\Costing\Application\RebuildCurrentCosts;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Reconstruye la proyección del costo vigente desde el historial (P4).
 *
 * Existir es parte de la decisión: una proyección sin forma de reconstruirse deja de ser una caché y
 * se convierte en una verdad paralela, que es exactamente lo que la especificación prohíbe para los
 * cortes ("calculados del diario, nunca almacenados como verdad paralela", §6.5).
 *
 * `--check` no escribe: reporta divergencias y devuelve código 1 si encuentra alguna, para poder
 * colgarlo de un chequeo periódico.
 *
 * Recorre los tenants **explícitamente**, uno por uno, dentro del contexto de cada uno. Es una de
 * las poquísimas operaciones legítimamente multi-tenant del sistema, y por eso es un comando de
 * consola y no un endpoint: la Regla B de ADR-002 prohíbe consultas cross-tenant en código de
 * dominio, no que una tarea de mantenimiento itere sobre tenants abriendo contexto en cada uno.
 */
final class RebuildCurrentCostsCommand extends Command
{
    protected $signature = 'comandia:costs:rebuild
        {--tenant= : Slug o ULID de un negocio concreto; si se omite, todos}
        {--check : Sólo reporta divergencias, sin escribir}';

    protected $description = 'Reconstruye (o verifica) la proyección del costo vigente desde el historial de costos.';

    public function handle(RebuildCurrentCosts $rebuild, TenantContext $context): int
    {
        $tenants = $this->resolveTenants();

        if ($tenants === []) {
            $this->components->error('No se encontró ningún negocio con ese identificador.');

            return self::FAILURE;
        }

        $check = (bool) $this->option('check');
        $totalDivergences = 0;

        foreach ($tenants as $tenant) {
            $context->runFor($tenant->id, function () use ($rebuild, $tenant, $check, &$totalDivergences): void {
                if ($check) {
                    $divergences = $rebuild->divergences();
                    $totalDivergences += count($divergences);

                    if ($divergences === []) {
                        $this->components->twoColumnDetail($tenant->name, '<fg=green>sin divergencias</>');

                        return;
                    }

                    $this->components->twoColumnDetail(
                        $tenant->name,
                        sprintf('<fg=red>%d divergencia(s)</>', count($divergences))
                    );

                    foreach (array_slice($divergences, 0, 10) as $divergence) {
                        $this->line(sprintf(
                            '    artículo #%d: proyección %s, historial %s',
                            $divergence['article_id'],
                            $divergence['projected'] ?? 'sin costo',
                            $divergence['expected'] ?? 'sin costo',
                        ));
                    }

                    return;
                }

                $result = $rebuild->forCurrentTenant();

                $this->components->twoColumnDetail(
                    $tenant->name,
                    sprintf(
                        '%d artículo(s), %d proyectado(s), %d limpiado(s)',
                        $result['articles'],
                        $result['projected'],
                        $result['cleared'],
                    )
                );
            });
        }

        if ($check && $totalDivergences > 0) {
            $this->components->error(sprintf(
                '%d artículo(s) con proyección distinta al historial. Corre el comando sin --check.',
                $totalDivergences
            ));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return list<Tenant>
     */
    private function resolveTenants(): array
    {
        $identifier = $this->option('tenant');

        // `Tenant` no lleva global scope —es la raíz del aislamiento—, así que esta consulta es
        // legítimamente global.
        if ($identifier === null) {
            return Tenant::query()->orderBy('name')->get()->all();
        }

        return Tenant::query()
            ->where('slug', $identifier)
            ->orWhere('ulid', $identifier)
            ->get()
            ->all();
    }
}
