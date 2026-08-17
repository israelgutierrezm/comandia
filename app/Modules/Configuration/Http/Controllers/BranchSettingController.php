<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Http\Controllers;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Configuration\Application\Settings;
use App\Modules\Configuration\Domain\Enums\SettingScope;
use App\Modules\Configuration\Domain\SettingCatalog;
use App\Modules\Configuration\Domain\SettingDefinition;
use App\Modules\Configuration\Http\Requests\UpdateSettingRequest;
use App\Modules\Configuration\Http\Resources\SettingResource;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Application\Authorization\ModuleGate;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Configuración a nivel sucursal — el nivel más específico de la cascada
 * (ARQUITECTURA_MAESTRA §5).
 *
 * Sólo lista las llaves que **admiten** override por sucursal. Mostrar aquí `locale` o el markup
 * por defecto sería ofrecer un control que devuelve 422 al guardarlo, y el usuario no tiene por
 * qué aprender el nivel máximo de cada llave probando.
 */
final class BranchSettingController
{
    public function __construct(
        private readonly Settings $settings,
        private readonly AuditLogger $audit,
        private readonly ModuleGate $modules,
    ) {}

    public function index(Branch $branch): JsonResponse
    {
        $resources = [];

        foreach (SettingCatalog::all() as $key => $definition) {
            if (! $definition->allowsScope(SettingScope::Branch) || ! $this->moduleAvailable($definition)) {
                continue;
            }

            $resources[] = new SettingResource(
                definition: $definition,
                value: $this->settings->forBranch($key, $branch->id),
                isOverridden: $this->settings->hasBranchOverride($key, $branch->id),
                // Lo heredado aquí es el valor de tenant, que a su vez puede ser el default: es
                // el valor al que volvería esta sucursal si se quita su override.
                inheritedValue: $this->settings->get($key),
            );
        }

        return new JsonResponse(['data' => collect($resources)->map->toArray(request())]);
    }

    public function update(UpdateSettingRequest $request, Branch $branch): JsonResponse
    {
        $definition = $request->definition();

        $antes = $this->settings->forBranch($definition->key, $branch->id);

        // El servicio rechaza el override si la llave sólo llega a tenant; el mensaje se traduce a
        // 422 por el formato de error uniforme.
        $this->settings->setForBranch($definition->key, $branch->id, $request->typedValue());

        $this->audit->log(
            action: AuditAction::SETTING_UPDATED,
            auditable: $branch,
            before: ['key' => $definition->key, 'value' => $antes, 'scope' => 'branch'],
            after: [
                'key' => $definition->key,
                'value' => $this->settings->forBranch($definition->key, $branch->id),
                'scope' => 'branch',
            ],
        );

        return $this->single($definition, $branch);
    }

    public function destroy(Branch $branch, string $key): JsonResponse
    {
        if (! SettingCatalog::has($key)) {
            throw new NotFoundHttpException(sprintf('La llave de configuración «%s» no existe.', $key));
        }

        $definition = SettingCatalog::get($key);
        $antes = $this->settings->forBranch($key, $branch->id);

        $this->settings->resetForBranch($key, $branch->id);

        $this->audit->log(
            action: AuditAction::SETTING_UPDATED,
            auditable: $branch,
            before: ['key' => $key, 'value' => $antes, 'scope' => 'branch'],
            after: [
                'key' => $key,
                'value' => $this->settings->forBranch($key, $branch->id),
                'scope' => 'branch',
                'reset' => true,
            ],
        );

        return $this->single($definition, $branch);
    }

    private function single(SettingDefinition $definition, Branch $branch): JsonResponse
    {
        $resource = new SettingResource(
            definition: $definition,
            value: $this->settings->forBranch($definition->key, $branch->id),
            isOverridden: $this->settings->hasBranchOverride($definition->key, $branch->id),
            inheritedValue: $this->settings->get($definition->key),
        );

        return new JsonResponse(['data' => $resource->toArray(request())]);
    }

    private function moduleAvailable(SettingDefinition $definition): bool
    {
        /** @var array<string, array{layer: string, activatable: bool, iteration: int}> $registro */
        $registro = (array) config('comandia.modules', []);

        if (! isset($registro[$definition->module]) || $registro[$definition->module]['activatable'] === false) {
            return true;
        }

        return $this->modules->isEnabled($definition->module);
    }
}
