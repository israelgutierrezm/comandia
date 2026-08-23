<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * El correo de un reporte programado (Tanda D3): el archivo generado, adjunto, con el remitente del negocio.
 */
final class ScheduledReportMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly string $label,
        private readonly string $fromAddress,
        private readonly string $fromName,
        private readonly string $filePath,
        private readonly string $fileName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->fromAddress, $this->fromName),
            subject: "Reporte: {$this->label}",
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: "<p>Adjuntamos tu reporte programado «{$this->label}».</p>",
        );
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return [Attachment::fromPath($this->filePath)->as($this->fileName)];
    }
}
