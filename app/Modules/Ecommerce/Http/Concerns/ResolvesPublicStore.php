<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Http\Concerns;

use App\Modules\Ecommerce\Infrastructure\Models\Store;
use App\Modules\Shared\Application\Authorization\ModuleGate;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Resuelve la tienda pública por su slug (Iteración 8, Tanda B), para las superficies sin sesión de `/t/{slug}`.
 *
 * Igual que el menú público: la superficie no tiene contexto de tenant, así que el **slug** —único globalmente— resuelve
 * el negocio. Se busca la tienda SIN scope (única forma antes de que exista contexto), se fija el contexto del negocio
 * dueño, y se verifica que el módulo esté activo y la tienda encendida. Una tienda inexistente, apagada o de un módulo
 * desactivado no existe para el público (404).
 */
trait ResolvesPublicStore
{
    protected function resolveStore(string $slug): Store
    {
        // Resolución por slug ANTES de que exista contexto: cross-tenant justificado (ver AuthorizationDisciplineTest).
        $store = Store::query()
            ->withoutGlobalScopes()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->first();

        if ($store === null) {
            throw new NotFoundHttpException('Tienda no encontrada.');
        }

        // A partir de aquí, todo acotado al negocio dueño de la tienda.
        app(TenantContext::class)->set((int) $store->tenant_id);

        if (! app(ModuleGate::class)->isEnabled('Ecommerce')) {
            throw new NotFoundHttpException('Tienda no disponible.');
        }

        return $store;
    }
}
