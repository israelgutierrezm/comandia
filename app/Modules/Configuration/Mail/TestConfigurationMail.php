<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * El correo de prueba de la configuración SMTP (Tanda D1). Si llega, la configuración del negocio sirve.
 *
 * El remitente es el del negocio (no el global): así el destinatario ve de quién viene y se valida el `from` configurado.
 */
final class TestConfigurationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly string $fromAddress,
        private readonly string $fromName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->fromAddress, $this->fromName),
            subject: 'Prueba de correo — Comandia',
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: '<p>Si recibes este correo, la configuración de correo de tu negocio en Comandia funciona.</p>',
        );
    }
}
