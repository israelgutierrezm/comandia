<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Http\Controllers;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Configuration\Application\TenantMailer;
use App\Modules\Configuration\Http\Requests\SendTestMailRequest;
use App\Modules\Configuration\Http\Requests\StoreMailSettingRequest;
use App\Modules\Configuration\Infrastructure\Models\TenantMailSetting;
use Illuminate\Http\JsonResponse;

/**
 * Configuración de correo del negocio (Tanda D1).
 *
 * ## La contraseña nunca sale
 *
 * `show` devuelve todo MENOS la contraseña —sólo si hay una guardada—; la pantalla muestra «configurado». En `update`, si
 * la contraseña llega vacía se conserva la que había (para editar el host sin re-teclearla). La contraseña se guarda
 * cifrada (cast del modelo). Cambiarla es acción sensible: se audita, sin el secreto.
 */
final class MailSettingController
{
    public function __construct(
        private readonly TenantMailer $mailer,
        private readonly AuditLogger $audit,
    ) {}

    public function show(): JsonResponse
    {
        $settings = $this->mailer->settings();

        if ($settings === null) {
            return new JsonResponse(['data' => ['configured' => false]]);
        }

        return new JsonResponse(['data' => [
            'configured' => true,
            'host' => $settings->host,
            'port' => $settings->port,
            'encryption' => $settings->encryption,
            'username' => $settings->username,
            'from_address' => $settings->from_address,
            'from_name' => $settings->from_name,
            'verified_at' => $settings->verified_at?->toIso8601String(),
            // La contraseña NUNCA se devuelve: sólo si hay una guardada.
            'has_password' => true,
        ]]);
    }

    public function update(StoreMailSettingRequest $request): JsonResponse
    {
        $settings = $this->mailer->settings() ?? new TenantMailSetting();

        $settings->fill($request->only(['host', 'port', 'encryption', 'username', 'from_address', 'from_name']));

        if ($request->filled('password')) {
            $settings->password = (string) $request->string('password');
        } elseif (! $settings->exists) {
            // Primer alta sin contraseña: no se puede.
            abort(422, 'La contraseña es obligatoria la primera vez.');
        }

        // Cambiar la configuración invalida la verificación previa: hay que volver a probar.
        $settings->verified_at = null;
        $settings->save();

        // Auditoría SIN el secreto y SIN auditable: es config singleton, no una entidad con ULID público (AuditLogger
        // sólo acepta modelos con ULID o null). La identificación va en el `after`.
        $this->audit->log(
            action: AuditAction::SETTING_UPDATED,
            after: ['setting' => 'mail', 'host' => $settings->host, 'username' => $settings->username, 'from_address' => $settings->from_address],
        );

        return $this->show();
    }

    public function sendTest(SendTestMailRequest $request): JsonResponse
    {
        if (! $this->mailer->isConfigured()) {
            abort(422, 'Configura el correo antes de enviar una prueba.');
        }

        $this->mailer->sendTest((string) $request->string('email'));

        return new JsonResponse(['data' => ['sent' => true]]);
    }
}
