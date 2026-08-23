<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Application;

use App\Modules\Configuration\Infrastructure\Models\TenantMailSetting;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Envía correo con la configuración SMTP DEL NEGOCIO (Tanda D1).
 *
 * Cada negocio configura su propio SMTP (D1); este servicio arma un mailer con esa config al vuelo y envía. Si el negocio
 * no ha configurado correo, cae al mailer por omisión (`log` en desarrollo) para no romper nada — un aviso que no sale no
 * debe tumbar la operación.
 *
 * Lo usan las notificaciones (D2) y los reportes programados (D3). Vive en `Configuration` (kernel) para que cualquier
 * módulo pueda enviar sin acoplarse.
 */
final class TenantMailer
{
    /** Nombre del mailer efímero que se arma por petición/job con la config del negocio. */
    private const TENANT_MAILER = '_tenant';

    /**
     * @return TenantMailSetting|null  la config del tenant actual (por el global scope), o null si no hay
     */
    public function settings(): ?TenantMailSetting
    {
        return TenantMailSetting::query()->first();
    }

    public function isConfigured(): bool
    {
        return $this->settings() !== null;
    }

    /**
     * Envía un Mailable al destinatario con el SMTP del negocio. Si no hay config, usa el mailer por omisión.
     */
    public function send(string $to, Mailable $mailable): void
    {
        $settings = $this->settings();

        if ($settings === null) {
            Mail::to($to)->send($mailable);

            return;
        }

        $this->configure($settings);

        try {
            Mail::mailer(self::TENANT_MAILER)->to($to)->send($mailable);
        } finally {
            // Se descarta el mailer resuelto para que el siguiente envío (otro tenant en el mismo worker) no herede esta
            // config. Bajo `Mail::fake()` el manager no tiene `purge`: se ignora.
            try {
                app('mail.manager')->purge(self::TENANT_MAILER);
            } catch (Throwable) {
                // sin efecto: entorno de prueba
            }
        }
    }

    /**
     * Envía el correo de prueba y, si sale, marca la config como verificada.
     */
    public function sendTest(string $to): void
    {
        $settings = $this->settings();

        if ($settings === null) {
            return;
        }

        $this->send($to, new \App\Modules\Configuration\Mail\TestConfigurationMail($settings->from_address, $settings->from_name));

        $settings->forceFill(['verified_at' => now()])->save();
    }

    private function configure(TenantMailSetting $settings): void
    {
        Config::set('mail.mailers.'.self::TENANT_MAILER, [
            'transport' => 'smtp',
            'host' => $settings->host,
            'port' => $settings->port,
            // Laravel espera 'tls'/'ssl' o nada; 'none' → sin cifrado.
            'encryption' => $settings->encryption === 'none' ? null : $settings->encryption,
            'username' => $settings->username,
            'password' => $settings->password, // el cast lo descifra
            'timeout' => 15,
        ]);
    }
}
