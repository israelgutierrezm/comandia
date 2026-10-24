<?php

declare(strict_types=1);

namespace App\Modules\Platform\Mail;

use App\Support\Queue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Aviso al super administrador de que su contraseña de la plataforma cambió (D382).
 *
 * Sale de Comandia (`MAIL_FROM_ADDRESS`), en cola: cambiar la contraseña no espera a un servidor de correo. Si no fue él,
 * es lo que le avisa.
 */
final class PlatformPasswordChangedMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  'panel'|'consola'  $via
     */
    public function __construct(
        public readonly string $adminName,
        public readonly string $changedAt,
        public readonly string $via,
        public readonly ?string $ip = null,
    ) {
        $this->onQueue(Queue::Default->value);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Cambió tu contraseña de la plataforma Comandia');
    }

    public function content(): Content
    {
        $nombre = e($this->adminName);
        $cuando = e($this->changedAt);
        $donde = $this->via === 'consola'
            ? 'desde la consola del servidor'
            : 'desde «Mi cuenta» en el panel'.($this->ip === null ? '' : ' (IP '.e($this->ip).')');

        return new Content(htmlString: <<<HTML
            <p>Hola, {$nombre}:</p>
            <p>La contraseña de tu cuenta de super administración de Comandia cambió el {$cuando} (hora del centro de
            México), {$donde}. Las demás sesiones abiertas se cerraron.</p>
            <p>Si no fuiste tú, avisa de inmediato a quien opera el servidor de Comandia.</p>
            HTML);
    }
}
