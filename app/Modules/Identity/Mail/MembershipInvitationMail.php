<?php

declare(strict_types=1);

namespace App\Modules\Identity\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * La invitación para entrar a un negocio (diseño de acceso, fase 3).
 *
 * Sale del correo del NEGOCIO —es el negocio quien invita (decisión 2 del diseño)— y, si el negocio no configuró el suyo,
 * del de Comandia. Lo envía el trabajo `SendMembershipInvitation`, en cola, con el contexto del negocio abierto.
 */
final class MembershipInvitationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  list<string>  $roles
     */
    public function __construct(
        public readonly string $businessName,
        public readonly string $inviterName,
        public readonly array $roles,
        public readonly string $link,
        public readonly int $daysValid,
        private readonly ?string $fromAddress = null,
        private readonly ?string $fromName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->fromAddress === null ? null : new Address($this->fromAddress, $this->fromName ?? $this->businessName),
            subject: sprintf('%s te invita a Comandia', $this->businessName),
        );
    }

    public function content(): Content
    {
        $negocio = e($this->businessName);
        $quien = e($this->inviterName);
        $enlace = e($this->link);
        $como = $this->roles === []
            ? ''
            : ' como '.e(implode(', ', $this->roles));

        return new Content(htmlString: <<<HTML
            <p>Hola:</p>
            <p>{$quien} te invitó a entrar a <strong>{$negocio}</strong> en Comandia{$como}.</p>
            <p><a href="{$enlace}">Aceptar la invitación</a></p>
            <p>Si es tu primera vez en Comandia, ahí creas tu contraseña. Si ya lo usas en otro negocio, entras con la tuya
            de siempre.</p>
            <p>El enlace vence en {$this->daysValid} días y sólo sirve una vez. Si no esperabas esta invitación, ignora este
            correo: nadie entrará con tu nombre.</p>
            HTML);
    }
}
