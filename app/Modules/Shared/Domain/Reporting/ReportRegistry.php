<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Reporting;

/**
 * El registro de definiciones de reporte del kernel (ADR-009).
 *
 * Cada módulo dueño registra sus `ReportDefinition` aquí en el `boot()` de su ServiceProvider —como se registran los
 * listeners y los probes—, y el motor de `Reporting` las lee. Es un singleton del kernel: `Reporting` no conoce a ningún
 * módulo de dominio, y ningún módulo de dominio conoce a `Reporting`; los dos conocen sólo esta pieza del kernel.
 */
final class ReportRegistry
{
    /** @var array<string, ReportDefinition> */
    private array $definitions = [];

    public function register(ReportDefinition $definition): void
    {
        $this->definitions[$definition->key()] = $definition;
    }

    public function get(string $key): ?ReportDefinition
    {
        return $this->definitions[$key] ?? null;
    }

    /**
     * @return list<ReportDefinition>
     */
    public function all(): array
    {
        return array_values($this->definitions);
    }
}
