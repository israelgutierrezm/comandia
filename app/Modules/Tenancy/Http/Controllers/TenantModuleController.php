<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\ManageTenantModules;
use App\Modules\Tenancy\Http\Requests\UpdateTenantModuleRequest;
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
    public function __construct(private readonly ManageTenantModules $modules) {}

    public function index(): JsonResponse
    {
        return new JsonResponse(['data' => $this->present()]);
    }

    public function update(UpdateTenantModuleRequest $request, string $module): JsonResponse
    {
        $this->modules->set($module, $request->boolean('enabled'));

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
