<?php

declare(strict_types=1);

namespace App\Modules\Customers\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Aviso al correo ANTERIOR de un cliente con cuenta en la tienda: el negocio le cambió el correo (D381).
 *
 * El buzón anterior puede ser de un desconocido —un correo mal escrito que el negocio corrige—, así que el aviso no le
 * regala nada: el correo nuevo va enmascarado (`j***@g***.com`) y no lleva el nombre del cliente. Sale del correo del
 * negocio y, si no configuró el suyo, del de Comandia con su nombre.
 */
final class CustomerEmailChangedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $businessName,
        public readonly string $maskedNewEmail,
        private readonly ?string $fromAddress = null,
        private readonly ?string $fromName = null,
    ) {}

    /**
     * `juan.perez@gmail.com` → `j***@g***.com`.
     */
    public static function mask(string $email): string
    {
        [$local, $dominio] = array_pad(explode('@', $email, 2), 2, '');
        $partes = explode('.', $dominio);
        $terminacion = count($partes) > 1 ? array_pop($partes) : '';

        return sprintf(
            '%s***@%s***%s',
            mb_substr($local, 0, 1),
            mb_substr(implode('.', $partes), 0, 1),
            $terminacion === '' ? '' : '.'.$terminacion,
        );
    }

    public function envelope(): Envelope
    {
        $remitente = $this->fromAddress === null
            ? new Address((string) config('mail.from.address'), sprintf('%s (vía Comandia)', $this->businessName))
            : new Address($this->fromAddress, $this->fromName ?? $this->businessName);

        return new Envelope(
            from: $remitente,
            subject: sprintf('Cambió el correo de tu cuenta en %s', $this->businessName),
        );
    }

    public function content(): Content
    {
        $negocio = e($this->businessName);
        $nuevo = e($this->maskedNewEmail);

        return new Content(htmlString: <<<HTML
            <p>Hola:</p>
            <p>El correo de tu cuenta en <strong>{$negocio}</strong> cambió a {$nuevo}. Desde ahora entras a su tienda en
            línea con ese correo.</p>
            <p>Si no lo pediste, comunícate con {$negocio}.</p>
            HTML);
    }
}
