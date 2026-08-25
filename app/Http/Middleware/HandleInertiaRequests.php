<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Configuration\Application\Settings;
use App\Modules\Configuration\Domain\Enums\AccentPreset;
use App\Modules\Identity\Application\MembershipNameResolver;
use App\Modules\Shared\Application\Authorization\Authorize;
use App\Modules\Shared\Application\Authorization\ModuleGate;
use App\Modules\Shared\Application\Context\ContextHolder;
use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Datos compartidos con el shell de Inertia.
 *
 * Frontera de D59: Inertia entrega únicamente el **shell** —identidad, contexto operativo, módulos
 * activos y permisos del rol activo para pintar la navegación—. Ningún dato transaccional viaja por
 * aquí: sucursales, personal, cortes y existencias se consumen desde `/api/v1`, para que la web y
 * la app Flutter usen exactamente la misma API (ARQUITECTURA_MAESTRA §8).
 *
 * La regla práctica para saber si algo pertenece a este archivo: si cambia mientras el usuario
 * trabaja, no va aquí. El shell se resuelve una vez por navegación; los datos cambian solos.
 */
final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'app' => [
                'name' => config('app.name'),
            ],

            'auth' => $this->auth($request),
            'context' => $this->context(),

            // Los permisos del ROL ACTIVO, no la suma de roles (D9). Es de aquí de donde saca
            // `v-can` su verdad, así que si esto trajera la suma, la UI ofrecería botones que el
            // servidor rechaza — y el usuario aprendería a desconfiar de la interfaz.
            'permissions' => app(Authorize::class)->permissionsOfActiveRole(),

            'active_modules' => $this->activeModules(),

            // El acento de marca del negocio (rediseño, Fase B). El layout lo inyecta en `--color-acento`. Es del shell:
            // no cambia mientras el usuario trabaja (salvo que lo cambie en Apariencia, que recarga el shell).
            'theme' => $this->theme(),

            // Mensajes de una navegación a la siguiente (tras crear, editar, dar de baja).
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function auth(Request $request): ?array
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        return [
            'email' => $user->email,
            'name' => $user->name()->short(),
        ];
    }

    /**
     * Contexto operativo: `{tenant, membresía, rol activo, sucursal activa, terminal}`.
     *
     * Los ULID de rol y sucursal los reenvía el cliente en cada llamada a la API como headers
     * `X-Role` y `X-Branch`, y el servidor los revalida contra el alcance de la membresía. No es
     * redundante: la sesión sabe el tenant, pero el rol y la sucursal activos son elecciones del
     * operador que pueden cambiar sin recargar el shell.
     *
     * @return array<string, mixed>|null
     */
    private function context(): ?array
    {
        $holder = app(ContextHolder::class);

        if (! $holder->has()) {
            return null;
        }

        $context = $holder->get();
        $membership = $context->membership;

        return [
            'tenant' => [
                'ulid' => $context->tenant->ulid,
                'name' => $context->tenant->name,
                'status' => $context->tenant->status->value,
            ],

            // Un tenant en sólo lectura por impago sigue pudiendo consultar y exportar. La UI lo
            // usa para deshabilitar los botones de escritura en lugar de dejar que el usuario
            // descubra el 403 al guardar.
            'is_read_only' => $context->isReadOnly,

            'membership' => $membership === null ? null : [
                'ulid' => $membership->ulid,
                'display_name' => app(MembershipNameResolver::class)->resolve($membership)->short(),
                'employee_code' => $membership->employee_code,
            ],

            'role_ulid' => $context->activeRole?->ulid,
            'role_name' => $context->activeRole?->name,

            'branch_ulid' => $context->activeBranch?->ulid,
            'branch_name' => $context->activeBranch?->name,
            'branch_timezone' => $context->activeBranch?->timezone,

            'terminal_ulid' => $context->terminal?->ulid,
        ];
    }

    /**
     * @return list<string>
     */
    private function activeModules(): array
    {
        return app(ModuleGate::class)->enabledModules();
    }

    /**
     * El acento resuelto del negocio: su preset de apariencia, o la terracota por omisión (y cuando no hay tenant, como
     * en el login). Se devuelve el hex ya resuelto para que el frontend sólo lo inyecte, sin conocer la paleta.
     *
     * @return array{key: string, accent: string}
     */
    private function theme(): array
    {
        $key = app(ContextHolder::class)->has()
            ? (string) app(Settings::class)->get('appearance.accent')
            : AccentPreset::Terracota->value;

        return ['key' => $key, 'accent' => AccentPreset::hexFor($key)];
    }
}
