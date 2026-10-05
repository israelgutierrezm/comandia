<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Identity\Infrastructure\Models\PersonalAccessToken;
use App\Modules\Identity\Infrastructure\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cambiar y restablecer la contraseña de una cuenta (diseño de acceso, fase 2).
 *
 * ## Las dos cierran todo lo demás (decisión 3 del diseño)
 *
 * Quien cambia su contraseña porque sospecha algo necesita que el otro quede fuera en ese momento. Así que las dos
 * cierran las sesiones de la app en TODOS los negocios de la persona, y las sesiones web caen solas en su siguiente
 * petición: `AuthenticateSession` compara el cifrado de la contraseña guardado en cada sesión con el nuevo. También se
 * renueva el `remember_token`, que invalida los «mantener la sesión abierta».
 *
 * La diferencia es qué se conserva. **Cambiar** se hace desde una sesión abierta, que sigue abierta —si se pidió desde la
 * app, su token no se revoca—. **Restablecer** llega desde un enlace, sin sesión, y no deja ninguna: tampoco inicia una
 * (un enlace robado no se convierte en una sesión abierta).
 *
 * ## Y restablecer verifica el correo
 *
 * El enlace llegó a ese buzón: es la prueba que `email_verified_at` pide, y hasta hoy nada la daba.
 */
final readonly class PasswordChanges
{
    public function __construct(
        private AppSessions $sessions,
        private AccountAudit $accountAudit,
    ) {}

    public function change(User $user, string $newPassword, ?PersonalAccessToken $keep = null): void
    {
        DB::transaction(function () use ($user, $newPassword, $keep): void {
            $this->store($user, $newPassword, markVerified: false);

            $this->sessions->revokeAllOf($user, 'password', except: $keep);

            $this->accountAudit->inEveryBusinessOf($user, AuditAction::PASSWORD_CHANGED);
        });
    }

    public function reset(User $user, string $newPassword): void
    {
        DB::transaction(function () use ($user, $newPassword): void {
            $this->store($user, $newPassword, markVerified: true);

            $this->sessions->revokeAllOf($user, 'password');

            $this->accountAudit->inEveryBusinessOf($user, AuditAction::PASSWORD_RESET);
        });
    }

    private function store(User $user, string $password, bool $markVerified): void
    {
        $user->forceFill([
            // El cast `hashed` del modelo la cifra: aquí nunca se guarda en claro.
            'password' => $password,
            'remember_token' => Str::random(60),
        ]);

        if ($markVerified && $user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => CarbonImmutable::now()]);
        }

        $user->save();
    }
}
