<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Tenancy\Application\ManageTenantModules;
use App\Modules\Tenancy\Http\Requests\UpdateTenantModuleRequest;
use App\Modules\Tenancy\Infrastructure\Models\TenantModule;
use Illuminate\Http\JsonResponse;

/**
 * Módulos activables del tenant (Iteración 8, Tanda A).
 *
 * El Propietario enciende o apaga Tienda y Menús. Ver exige `tenancy.modules.view`; cambiar, `tenancy.modules.manage` —los
 * dos fuera del gerente porque activar un módulo es una decisión comercial (D4)—. Un tenant sin un módulo no ejecuta su
 * código: esta pantalla es lo que hace visible y operable esa promesa.
 */
final class TenantModuleController
{
    public function __construct(
        private readonly ManageTenantModules $modules,
        private readonly AuditLogger $audit,
    ) {}

    public function index(): JsonResponse
    {
        return new JsonResponse(['data' => $this->present()]);
    }

    public function update(UpdateTenantModuleRequest $request, string $module): JsonResponse
    {
        $antes = $this->modules->state()[$module] ?? null;
        $activar = $request->boolean('enabled');

        $this->modules->set($module, $activar);

        // Apagar la tienda saca de línea la tienda pública, la bandeja de pedidos y la entrada de los marketplaces: es
        // una decisión comercial con consecuencias, y la bitácora tenía la acción en su catálogo pero nadie la
        // registraba. Sólo cuando el estado CAMBIA: guardar lo mismo otra vez no es un hecho.
        if ($antes !== $activar) {
            $this->audit->log(
                action: $activar ? AuditAction::TENANT_MODULE_ENABLED : AuditAction::TENANT_MODULE_DISABLED,
                auditable: TenantModule::query()->where('module', $module)->sole(),
                before: ['module' => $module, 'enabled' => $antes],
                after: ['module' => $module, 'enabled' => $activar],
            );
        }

        return new JsonResponse(['data' => $this->present()]);
    }

    /**
     * El estado de cada módulo activable, con su etiqueta legible del registro (`config/comandia.php`).
     *
     * @return list<array{module: string, label: string, enabled: bool}>
     */
    private function present(): array
    {
        $labels = collect((array) config('comandia.modules', []))
            ->map(fn (array $m): string => (string) ($m['label'] ?? ''));

        $out = [];

        foreach ($this->modules->state() as $module => $enabled) {
            $out[] = [
                'module' => $module,
                'label' => $labels->get($module, $module),
                'enabled' => $enabled,
            ];
        }

        return $out;
    }
}
