<?php

declare(strict_types=1);

namespace App\Modules\DigitalMenus\Http\Controllers;

use App\Modules\DigitalMenus\Application\AssembleMenu;
use App\Modules\DigitalMenus\Infrastructure\Models\DigitalMenu;
use App\Modules\Shared\Application\Authorization\ModuleGate;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * El menú público por QR (Iteración 8, Tanda A): `/m/{slug}`, sin autenticación.
 *
 * ## El slug resuelve el tenant, no la sesión
 *
 * Una superficie pública no tiene sesión ni contexto de tenant. Aquí el **slug** —único globalmente— es quien resuelve el
 * negocio: se busca el menú SIN el scope de tenant (única forma antes de que exista contexto, como el selector de negocio
 * del login o el token del agente de impresión), se fija el contexto del tenant dueño y a partir de ahí todo queda acotado.
 * Un menú inactivo, o de un negocio que apagó el módulo, no existe para el público (404).
 */
final class PublicMenuController
{
    public function __construct(
        private readonly AssembleMenu $assembler,
        private readonly TenantContext $tenant,
        private readonly ModuleGate $modules,
    ) {}

    public function show(string $slug): View
    {
        // Resolución por slug ANTES de que exista contexto: cross-tenant justificado (ver AuthorizationDisciplineTest).
        $menu = DigitalMenu::query()
            ->withoutGlobalScopes()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->first();

        if ($menu === null) {
            throw new NotFoundHttpException('Menú no encontrado.');
        }

        // A partir de aquí, todo acotado al negocio dueño del menú.
        return $this->tenant->runFor((int) $menu->tenant_id, function () use ($menu): View {
            // Un negocio que apagó el módulo de menús no sirve su superficie pública, ni aunque el slug siga vivo.
            if (! $this->modules->isEnabled('DigitalMenus')) {
                throw new NotFoundHttpException('Menú no disponible.');
            }

            $data = $this->assembler->forMenu($menu->load('branch'));

            // El shell rinde el título/meta server-side (SEO) e incrusta los datos para que la SPA hidrate sin una segunda
            // petición.
            return view('menu.public', ['menu' => $data]);
        });
    }
}
