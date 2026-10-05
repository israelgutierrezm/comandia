<?php

declare(strict_types=1);

namespace App\Modules\Identity\Mail;

use App\Support\Queue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * El enlace para crear una contraseña nueva (diseño de acceso, fase 2).
 *
 * Sale del correo de COMANDIA, no del de un negocio (decisión 2 del diseño): la cuenta es de la plataforma y el enlace
 * se pide sin negocio. Por cola (`default`), para que la respuesta de «olvidé mi contraseña» tarde lo mismo exista o no
 * la cuenta.
 *
 * El enlace llega ARMADO desde quien lo pide (`User::sendPasswordResetNotification`), con la dirección configurada de la
 * aplicación y nunca con la de la petición: si se armara con el encabezado `Host`, cualquiera podría pedir el enlace de
 * otra persona desde un dominio suyo y recibir el token cuando la víctima hiciera clic.
 */
final class ResetPasswordMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $link,
        public readonly string $firstName,
        public readonly int $minutes,
    ) {
        $this->onQueue(Queue::Default->value);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Tu contraseña de Comandia');
    }

    public function content(): Content
    {
        $nombre = e($this->firstName);
        $enlace = e($this->link);

        return new Content(htmlString: <<<HTML
            <p>Hola, {$nombre}:</p>
            <p>Alguien pidió crear una contraseña nueva para tu cuenta de Comandia. Si fuiste tú, entra a este enlace:</p>
            <p><a href="{$enlace}">Crear mi contraseña nueva</a></p>
            <p>Vence en {$this->minutes} minutos y sólo sirve una vez. Al guardar la nueva se cerrarán tus sesiones en todos
            lados, también en la app.</p>
            <p>Si no lo pediste, ignora este correo: tu contraseña no cambia.</p>
            HTML);
    }
}
