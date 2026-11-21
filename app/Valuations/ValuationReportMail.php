<?php

declare(strict_types=1);

namespace App\Valuations;

use App\Communications\Communications;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * E-mail 1 to the person who asked for the valuation (GHL strategy annex B.1 / B.1-R, L-9): the report attached, the
 * interval in the body, a text link to download it and ONE button: «Solicitar valoración personalizada» to the
 * calendar of the property's office. A «revision» or «error» result sends the notice variant (no figure, no PDF).
 * Replies to this mailbox do not reach GHL, so the text invites to answer the WhatsApp or call.
 */
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
            ? 'Su valoración de '.$this->valuation->shortAddress().' ya está lista'
            : 'Hemos recibido su solicitud de valoración de '.$this->valuation->shortAddress();
    }

    /** Plain-text version, also what the communications log keeps. */
    public function plainText(): string
    {
        $v = $this->vars();
        $booking = $v['booking'] !== null ? "\n\nSolicitar valoración personalizada: {$v['booking']}" : '';
        $footer = "\n\nUn saludo,\nEl equipo de CLARTO · {$v['office']}\n\n".self::footer($this->valuation->reference);
        if ($this->pdf === null) {
            return "Hola, {$v['name']}:\n\nHemos recibido su solicitud de valoración del inmueble en {$v['address']} (ref. {$this->valuation->reference}).\n\n"
                .($v['reason'] !== '' ? $v['reason']."\n\n" : '')
                ."Por eso, un asesor de CLARTO la revisará personalmente y le contactará en breve.\n\nSi lo prefiere, puede elegir ya el día para que visitemos la vivienda.{$booking}\n\n"
                .'También puede responder al WhatsApp que le hemos enviado o llamarnos al '.ReportData::PHONE.'.'.$footer;
        }

        return "Hola, {$v['name']}:\n\nYa tiene la valoración orientativa de su inmueble en {$v['address']} (ref. {$this->valuation->reference}).\n\n"
            ."Con los datos de Catastro, las estadísticas oficiales de precios y las características que nos ha indicado, estimamos que el precio de venta se sitúa entre {$v['min']} y {$v['max']}.\n\n"
            ."Tiene el informe completo adjunto en PDF. También puede descargarlo aquí hasta el {$v['until']}:\n{$this->link}\n\n"
            .($v['low'] ? "En su zona hay pocos datos de operaciones recientes, por eso el intervalo es amplio.\n\n" : '')
            ."Es una estimación automática, no una tasación oficial. El estado real de la vivienda, las reformas, la altura, la orientación y la luz, las vistas o la situación de la comunidad pueden cambiar el precio al que conviene ofrecerla.\n\n"
            .'Si está pensando en vender, un asesor de CLARTO puede visitar la vivienda y concretar con usted una valoración personalizada. No tiene coste ni le compromete a nada.'.$booking."\n\n"
            .'¿Prefiere hablarlo antes? Responda al WhatsApp que le hemos enviado o llámenos al '.ReportData::PHONE.'.'.$footer;
    }

    /** Footer of Liberu's (transactional) e-mails, annex B.0. */
    public static function footer(?string $reference): string
    {
        return 'CLARTO · Lo complejo, claro. · '.ReportData::PHONE.' · WhatsApp '.ReportData::WHATSAPP.' · '.ReportData::EMAIL."\n"
            ."Le enviamos este correo porque solicitó una valoración en clarto.es (ref. {$reference}).\n"
            .'Responsable: Carlos Miguel Landaeta Valdivia (CLARTO). Más información en https://clarto.es/privacidad';
    }

    /** @return array{name: string, address: string, office: string, booking: ?string, until: string, min: string, max: string, low: bool, reason: string} */
    public function vars(): array
    {
        $r = (array) $this->run->result;

        return [
            'name' => strtok(trim((string) ($this->valuation->contact['nombre'] ?? '')), ' ') ?: '',
            'address' => $this->valuation->shortAddress(),
            'office' => $this->valuation->office(),
            'booking' => $this->valuation->bookingUrl('correo1'),
            'until' => now()->addDays(max(1, (int) config('valoraciones.informe_dias', 30)))->format('d/m/Y'),
            'min' => isset($r['intervalo']['min']) ? ReportData::eur($r['intervalo']['min']) : '',
            'max' => isset($r['intervalo']['max']) ? ReportData::eur($r['intervalo']['max']) : '',
            'low' => ($r['suficiencia'] ?? null) === 'baja',
            'reason' => trim((string) $this->run->message),
        ];
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
            'v' => $this->vars(),
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
