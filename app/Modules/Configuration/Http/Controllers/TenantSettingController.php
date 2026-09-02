<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Http\Controllers;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Configuration\Application\Settings;
use App\Modules\Configuration\Domain\SettingCatalog;
use App\Modules\Configuration\Domain\SettingDefinition;
use App\Modules\Configuration\Http\Requests\UpdateSettingRequest;
use App\Modules\Configuration\Http\Resources\SettingResource;
use App\Modules\Shared\Application\Authorization\ModuleGate;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Configuración a nivel tenant (ARQUITECTURA_MAESTRA §5).
 *
 * Devuelve el catálogo completo con su valor efectivo, no sólo las filas que existen en base:
 * una llave sin override es igual de real que una con override, y el usuario tiene que poder
 * verla y cambiarla. Listar sólo lo guardado dejaría invisible todo lo que el tenant no ha
 * tocado, que al principio es todo.
 */
final class TenantSettingController
{
    public function __construct(
        private readonly Settings $settings,
        private readonly AuditLogger $audit,
        private readonly ModuleGate $modules,
    ) {}

    public function index(): JsonResponse
    {
        $resources = [];

        foreach (SettingCatalog::all() as $key => $definition) {
            if (! $definition->isOfferedToUser() || ! $this->moduleAvailable($definition)) {
                continue;
            }

            $resources[] = new SettingResource(
                definition: $definition,
                value: $this->settings->get($key),
                isOverridden: $this->settings->hasTenantOverride($key),
                // Sin override de tenant, lo heredado es el default del sistema.
                inheritedValue: $definition->default,
            );
        }

        return new JsonResponse(['data' => collect($resources)->map->toArray(request())]);
    }

    public function update(UpdateSettingRequest $request): JsonResponse
    {
        $definition = $request->definition();

        $antes = $this->settings->get($definition->key);

        $this->settings->setForTenant($definition->key, $request->typedValue());

        $despues = $this->settings->get($definition->key);

        $this->audit->log(
            action: AuditAction::SETTING_UPDATED,
            before: ['key' => $definition->key, 'value' => $antes, 'scope' => 'tenant'],
            after: ['key' => $definition->key, 'value' => $despues, 'scope' => 'tenant'],
        );

        return $this->single($definition);
    }

    /**
     * Quita el override para que la llave vuelva a heredar.
     *
     * Es una operación distinta de "poner el valor por defecto": si el default del sistema cambia
     * en una versión futura, una llave que hereda sigue el nuevo valor y una con override
     * explícito no. Confundirlas dejaría al tenant clavado en un default viejo sin saberlo.
     */
    public function destroy(string $key): JsonResponse
    {
        if (! SettingCatalog::has($key)) {
            throw new NotFoundHttpException(sprintf('La llave de configuración «%s» no existe.', $key));
        }

        $definition = SettingCatalog::get($key);
        $antes = $this->settings->get($key);

        $this->settings->resetForTenant($key);

        $this->audit->log(
            action: AuditAction::SETTING_UPDATED,
            before: ['key' => $key, 'value' => $antes, 'scope' => 'tenant'],
            after: ['key' => $key, 'value' => $definition->default, 'scope' => 'tenant', 'reset' => true],
        );

        return $this->single($definition);
    }

    private function single(SettingDefinition $definition): JsonResponse
    {
        $resource = new SettingResource(
            definition: $definition,
            value: $this->settings->get($definition->key),
            isOverridden: $this->settings->hasTenantOverride($definition->key),
            inheritedValue: $definition->default,
        );

        return new JsonResponse(['data' => $resource->toArray(request())]);
    }

    private function moduleAvailable(SettingDefinition $definition): bool
    {
        /** @var array<string, array{layer: string, activatable: bool, iteration: int}> $registro */
        $registro = (array) config('comandia.modules', []);

        $module = $definition->module;

        // Igual que con el catálogo de permisos (§4.2): no se le ofrece configurar un módulo que
        // no contrató. Ajustar la aceptación automática de pedidos sin tener tienda en línea sería
        // configurar algo que nunca se ejecuta.
        if (! isset($registro[$module]) || $registro[$module]['activatable'] === false) {
            return true;
        }

        return $this->modules->isEnabled($module);
    }
}
