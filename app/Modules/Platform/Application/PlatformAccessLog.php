<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application;

use App\Modules\Platform\Infrastructure\Models\PlatformAdmin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * La constancia de lo que hace un super administrador con su acceso (D382), en el canal de log `platform`.
 *
 * No es la bitácora de los negocios: `audit_entries` exige un `tenant_id` y la plataforma no es de ningún negocio. Lleva
 * el ULID del admin, la fecha en UTC y de dónde vino —IP y navegador desde el panel, el usuario del sistema operativo desde
 * la consola—. NUNCA una contraseña ni un token.
 */
final class PlatformAccessLog
{
    public const ADMIN_CREATED = 'platform.admin_created';

    public const PASSWORD_CHANGED = 'platform.password_changed';

    /**
     * @param  'panel'|'consola'  $via
     */
    public function record(string $event, PlatformAdmin $admin, string $via, ?Request $request = null): void
    {
        $origen = $request === null
            ? ['os_user' => $this->osUser()]
            : ['ip' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 300)];

        Log::channel('platform')->info($event, [
            'admin_ulid' => $admin->ulid,
            'via' => $via,
            'at' => now('UTC')->toIso8601String(),
            ...$origen,
        ]);
    }

    private function osUser(): ?string
    {
        $usuario = getenv('USERNAME') ?: getenv('USER') ?: null;

        return $usuario === null ? null : (string) $usuario;
    }
}
