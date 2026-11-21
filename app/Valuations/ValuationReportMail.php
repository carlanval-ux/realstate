<?php

declare(strict_types=1);

namespace App\Valuations;

use App\Communications\Communications;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/** E-mail to the person who asked for the valuation: the report attached and a signed link, or a «we will review it» notice. */
final class ValuationReportMail extends Mailable
{
    public function __construct(
        public readonly ValuationRequest $valuation,
        public readonly ValuationRun $run,
        private readonly ?string $pdf,
        public readonly ?string $link,
    ) {}

    public function subjectLine(): string
    {
        return $this->pdf !== null
            ? 'Su estimación orientativa · '.(new ReportData($this->valuation, $this->run))->displayAddress().' · CLARTO'
            : 'Hemos recibido su solicitud de valoración · CLARTO';
    }

    /** Plain-text version, also what the communications log keeps. */
    public function plainText(): string
    {
        $name = strtok(trim((string) ($this->valuation->contact['nombre'] ?? '')), ' ') ?: '';
        $where = (new ReportData($this->valuation, $this->run))->displayAddress();

        return $this->pdf !== null
            ? "Hola {$name}:\n\nLe enviamos la estimación orientativa del precio de venta de {$where} (ref. {$this->valuation->reference}). Tiene el informe en PDF adjunto y también puede descargarlo aquí:\n{$this->link}\n\nSi quiere afinar el resultado, solicite una valoración personalizada con CLARTO: ".ReportData::PHONE.' · WhatsApp '.ReportData::WHATSAPP.' · '.ReportData::EMAIL."\n\nCLARTO · Lo complejo, claro."
            : "Hola {$name}:\n\nHemos recibido su solicitud de valoración de {$where} (ref. {$this->valuation->reference}). Con los datos disponibles no podemos darle una estimación automática fiable, así que nuestro equipo la revisará personalmente y le contactará en breve.\n\n".ReportData::PHONE.' · WhatsApp '.ReportData::WHATSAPP.' · '.ReportData::EMAIL."\n\nCLARTO · Lo complejo, claro.";
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine());
    }

    public function headers(): Headers
    {
        return new Headers(text: [Communications::MAIL_HEADER => 'valoracion-'.$this->valuation->reference]);   // logged by ValuationDelivery
    }

    public function content(): Content
    {
        return new Content(view: 'mail.clarto-valoracion', text: 'mail.clarto-valoracion-texto', with: [
            'r' => (array) $this->run->result,
            'data' => new ReportData($this->valuation, $this->run),
            'hasReport' => $this->pdf !== null,
            'name' => strtok(trim((string) ($this->valuation->contact['nombre'] ?? '')), ' ') ?: '',
            'plain' => $this->plainText(),
        ]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return $this->pdf === null ? [] : [
            Attachment::fromData(fn (): string => (string) $this->pdf, 'CLARTO-estimacion-'.$this->valuation->reference.'.pdf')->withMime('application/pdf'),
        ];
    }
}
