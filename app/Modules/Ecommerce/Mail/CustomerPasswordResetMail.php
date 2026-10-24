<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * El enlace para que un cliente de la tienda cree una contraseña nueva (D381).
 *
 * Sale del correo del NEGOCIO —el cliente conoce al restaurante, no a Comandia— y, si el negocio no configuró el suyo, del
 * de Comandia con el nombre de la tienda. Lo envía el trabajo `SendCustomerPasswordResetLink`, con el contexto del negocio
 * abierto.
 */
final class CustomerPasswordResetMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $storeName,
        public readonly string $customerName,
        public readonly string $link,
        public readonly int $minutes,
        private readonly ?string $fromAddress = null,
        private readonly ?string $fromName = null,
    ) {}

    public function envelope(): Envelope
    {
        $remitente = $this->fromAddress === null
            ? new Address((string) config('mail.from.address'), sprintf('%s (vía Comandia)', $this->storeName))
            : new Address($this->fromAddress, $this->fromName ?? $this->storeName);

        return new Envelope(
            from: $remitente,
            subject: sprintf('Crea tu nueva contraseña en %s', $this->storeName),
        );
    }

    public function content(): Content
    {
        $tienda = e($this->storeName);
        $nombre = e($this->customerName);
        $enlace = e($this->link);

        return new Content(htmlString: <<<HTML
            <p>Hola, {$nombre}:</p>
            <p>Pediste crear una contraseña nueva para tu cuenta en <strong>{$tienda}</strong>.</p>
            <p><a href="{$enlace}">Crear mi contraseña nueva</a></p>
            <p>El enlace vale {$this->minutes} minutos y sólo sirve una vez. Al guardar la nueva se cierran las sesiones que
            tengas abiertas.</p>
            <p>Si no lo pediste, ignora este correo: tu contraseña no cambia.</p>
            HTML);
    }
}
