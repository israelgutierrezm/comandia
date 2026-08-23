<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Infrastructure\Models\TenantModule;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Activa o desactiva los módulos activables de un tenant (D3, Iteración 8).
 *
 * Es la primera vez que la activación de módulos se opera de verdad: hasta la Iteración 8 la tabla existía y el
 * `ModuleGate` la leía, pero nadie la escribía por una superficie. La escritura invalida la cache de módulos del tenant
 * automáticamente (lo hace el modelo `TenantModule` al guardar), así que encender la tienda surte efecto en la siguiente
 * petición, no en diez minutos.
 *
 * El nombre del módulo se valida contra el registro declarativo (`config/comandia.php`), no contra un enum: ese registro es
 * la única fuente de verdad sobre qué módulos existen y cuáles son activables.
 */
final class ManageTenantModules
{
    /**
     * El estado de cada módulo activable del tenant: contratado o no.
     *
     * @return array<string, bool>  módulo => activo
     */
    public function state(): array
    {
        $enabled = TenantModule::query()
            ->where('is_enabled', true)
            ->pluck('module')
            ->all();

        $state = [];

        foreach (TenantModule::activatableModules() as $module) {
            $state[$module] = in_array($module, $enabled, strict: true);
        }

        return $state;
    }

    public function set(string $module, bool $enabled): void
    {
        if (! in_array($module, TenantModule::activatableModules(), strict: true)) {
            // Un módulo que no existe o que no es activable no se «enciende»: se rechaza, no se ignora en silencio.
            throw new UnprocessableEntityHttpException("«{$module}» no es un módulo activable.");
        }

        $row = TenantModule::query()->where('module', $module)->first()
            ?? new TenantModule(['module' => $module]);

        $row->is_enabled = $enabled;

        if ($enabled) {
            $row->enabled_at = now();
            $row->disabled_at = null;
        } else {
            $row->disabled_at = now();
        }

        // `save()` dispara la invalidación de cache de módulos del tenant (ver TenantModule::booted).
        $row->save();
    }
}
