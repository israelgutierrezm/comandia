<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Application;

use App\Modules\Configuration\Domain\Enums\SettingScope;
use App\Modules\Configuration\Domain\Exceptions\SettingScopeViolationException;
use App\Modules\Configuration\Domain\SettingCatalog;
use App\Modules\Configuration\Infrastructure\Models\BranchSetting;
use App\Modules\Configuration\Infrastructure\Models\TenantSetting;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Resolución en cascada de la configuración (ARQUITECTURA_MAESTRA §5).
 *
 *     valor efectivo = branch_settings ?? tenant_settings ?? default del catálogo
 *
 * ## Cache
 *
 * Se cachea **el mapa completo** de cada nivel, no llave por llave. Dos razones: una
 * pantalla de POS consulta varias llaves por request —precorte ciego, bloqueo de
 * items, cobro de para llevar— y un mapa se trae en una consulta; y la invalidación
 * es una sola clave, no N.
 *
 * ## Escritura
 *
 * Escribir una llave que no está en el catálogo es error, no `INSERT`. Escribir a
 * nivel sucursal una llave cuyo `maxScope` es tenant es error. Las dos cosas
 * protegen de lo mismo: una fila que el usuario cree que configura algo y que el
 * sistema ignora, o peor, que sí lee con un efecto que nadie decidió.
 */
final class Settings
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly Cache $cache,
    ) {}

    /**
     * Valor efectivo a nivel tenant.
     */
    public function get(string $key): bool|int|string|float
    {
        $definition = SettingCatalog::get($key);

        $overrides = $this->tenantOverrides();

        return isset($overrides[$key])
            ? $definition->cast($overrides[$key])
            : $definition->default;
    }

    /**
     * Valor efectivo en una sucursal: el nivel más específico de la cascada.
     */
    public function forBranch(string $key, int $branchId): bool|int|string|float
    {
        $definition = SettingCatalog::get($key);

        $branchOverrides = $this->branchOverrides($branchId);

        if (isset($branchOverrides[$key])) {
            return $definition->cast($branchOverrides[$key]);
        }

        return $this->get($key);
    }

    /**
     * @throws SettingScopeViolationException
     */
    public function setForTenant(string $key, mixed $value): void
    {
        $definition = SettingCatalog::get($key);

        if (! $definition->allowsScope(SettingScope::Tenant)) {
            throw SettingScopeViolationException::make($key, SettingScope::Tenant, $definition->maxScope);
        }

        // Sin `tenant_id` en los atributos: el global scope ya acota el WHERE y
        // `BelongsToTenant` rellena la columna al crear. Pasarlo aquí sería asignación
        // masiva de una columna protegida, y el modelo lo rechaza a propósito.
        TenantSetting::query()->updateOrCreate(
            ['setting_key' => $key],
            ['setting_value' => $definition->serialize($value)],
        );

        $this->forgetTenant();
    }

    /**
     * @throws SettingScopeViolationException
     */
    public function setForBranch(string $key, int $branchId, mixed $value): void
    {
        $definition = SettingCatalog::get($key);

        if (! $definition->allowsScope(SettingScope::Branch)) {
            throw SettingScopeViolationException::make($key, SettingScope::Branch, $definition->maxScope);
        }

        BranchSetting::query()->updateOrCreate(
            ['branch_id' => $branchId, 'setting_key' => $key],
            ['setting_value' => $definition->serialize($value)],
        );

        $this->forgetBranch($branchId);
    }

    /**
     * Elimina el override, con lo que la llave vuelve a heredar del nivel superior.
     *
     * Es una operación distinta de "poner el valor por defecto": si el default del
     * sistema cambia en una versión futura, una llave que heredaba lo sigue el nuevo
     * valor y una llave con override explícito no. La diferencia importa.
     */
    public function resetForTenant(string $key): void
    {
        SettingCatalog::get($key);

        TenantSetting::query()
            ->where('setting_key', $key)
            ->delete();

        $this->forgetTenant();
    }

    public function resetForBranch(string $key, int $branchId): void
    {
        SettingCatalog::get($key);

        BranchSetting::query()
            ->where('branch_id', $branchId)
            ->where('setting_key', $key)
            ->delete();

        $this->forgetBranch($branchId);
    }

    /**
     * ¿La llave tiene override explícito en este nivel?
     *
     * Lo necesita la UI para distinguir "hereda" de "configurado con el mismo valor".
     */
    public function hasTenantOverride(string $key): bool
    {
        SettingCatalog::get($key);

        return isset($this->tenantOverrides()[$key]);
    }

    public function hasBranchOverride(string $key, int $branchId): bool
    {
        SettingCatalog::get($key);

        return isset($this->branchOverrides($branchId)[$key]);
    }

    /**
     * Todas las llaves efectivas de una sucursal, resueltas.
     *
     * Es lo que el shell del frontend necesita de una sola vez.
     *
     * @return array<string, bool|int|string|float>
     */
    public function allForBranch(int $branchId): array
    {
        $resolved = [];

        foreach (array_keys(SettingCatalog::all()) as $key) {
            $resolved[$key] = $this->forBranch($key, $branchId);
        }

        return $resolved;
    }

    // -----------------------------------------------------------------
    // Cache
    // -----------------------------------------------------------------

    /**
     * @return array<string, string>
     */
    private function tenantOverrides(): array
    {
        return $this->cache->rememberForever(
            $this->tenantCacheKey(),
            fn (): array => TenantSetting::query()
                ->pluck('setting_value', 'setting_key')
                ->all(),
        );
    }

    /**
     * @return array<string, string>
     */
    private function branchOverrides(int $branchId): array
    {
        return $this->cache->rememberForever(
            $this->branchCacheKey($branchId),
            fn (): array => BranchSetting::query()
                ->where('branch_id', $branchId)
                ->pluck('setting_value', 'setting_key')
                ->all(),
        );
    }

    private function forgetTenant(): void
    {
        $this->cache->forget($this->tenantCacheKey());
    }

    private function forgetBranch(int $branchId): void
    {
        $this->cache->forget($this->branchCacheKey($branchId));
    }

    private function tenantCacheKey(): string
    {
        return "comandia.settings.tenant.{$this->context->id()}";
    }

    private function branchCacheKey(int $branchId): string
    {
        return "comandia.settings.tenant.{$this->context->id()}.branch.{$branchId}";
    }
}
