<?php

declare(strict_types=1);

namespace App\Valuations;

use App\Communications\Communications;
use Carbon\CarbonInterface;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * E-mail 1 to the person who asked for the valuation (GHL strategy annex B.1 / B.1-R, L-9): the report attached, the
 * interval in the body, the short link to consult and download it (ReportLinks) and ONE button: «Solicitar valoración
 * personalizada». Same tone («tú») and the same invitation as WhatsApp 1 (owner, 21/11/2026); the PDF and the website
 * keep «usted» for now. A «revision» or «error» result sends the notice variant (no figure, no PDF).
 * Replies to this mailbox do not reach GHL, so the text invites to answer the WhatsApp or call.
 */
final class ValuationReportMail extends Mailable
{
    /** Automated message: signed by the team, never by an advisor (owner, 21/11/2026). Sender name «CLARTO». */
    public const SIGNATURE = 'Equipo CLARTO · '.ReportData::PHONE.' · '.ReportData::EMAIL;

    /** The owner's sales invitation (ReportData::SALES_*), in «tú» for the e-mail. */
    public const SALES_EYEBROW = 'VENDE CON CLARTO';

    public const SALES_TITLE = 'Vendemos tu vivienda con lo último en tecnología.';

    public const SALES_LEAD = 'Cerramos operaciones de más calidad en menos tiempo: tecnología de última generación, vídeos profesionales de tu inmueble y estrategias de venta de vanguardia, con un equipo de abogados, asesores y gestores a tu lado en cada paso.';

    /** @var list<array{0: string, 1: string, 2: string}> */
    public const SALES_PILLARS = [
        ['tecnologia', 'Lo último en tecnología', 'Valoración con datos oficiales, difusión digital, firma electrónica y seguimiento por WhatsApp para llegar antes al comprador adecuado.'],
        ['video', 'Vídeo y estrategia de vanguardia', 'Vídeos de tu inmueble y una estrategia de precio y comercialización pensada para vender más rápido y en mejores condiciones.'],
        ['equipo', 'Un equipo a tu lado en cada paso', 'Abogados, asesores fiscales y gestores de CLARTO: contratos, impuestos de la venta, documentación y firma ante notario. En toda España.'],
    ];

    public function __construct(
        public readonly ValuationRequest $valuation,
        public readonly ValuationRun $run,
        private readonly ?string $pdf,
        public readonly ?string $link,
        public readonly ?CarbonInterface $linkExpires = null,
    ) {}

    public function subjectLine(): string
    {
        return $this->pdf !== null
            ? 'Tu valoración de '.$this->valuation->shortAddress().' ya está lista'
            : 'Hemos recibido tu solicitud de valoración de '.$this->valuation->shortAddress();
    }

    /** Plain-text version, also what the communications log keeps (with the link's code shortened). */
    public function plainText(): string
    {
        $v = $this->vars();
        $booking = $v['booking'] !== null ? "\n\nSolicitar valoración personalizada: {$v['booking']}" : '';
        $footer = "\n\nUn saludo,\n".self::SIGNATURE."\n\n".self::footer($this->valuation->reference);
        if ($this->pdf === null) {
            return "Hola, {$v['name']}:\n\nHemos recibido tu solicitud de valoración del inmueble en {$v['address']} (ref. {$this->valuation->reference}).\n\n"
                .($v['reason'] !== '' ? $v['reason']."\n\n" : '')
                ."Por eso, el equipo de CLARTO la revisará personalmente y te contactará en breve.\n\nSi lo prefieres, puedes elegir ya el día para que visitemos la vivienda.{$booking}\n\n"
                .'¿Te viene mejor que te llamemos por la mañana o por la tarde? Responde al WhatsApp que te hemos enviado o llámanos al '.ReportData::PHONE.'.'.$footer;
        }

        return "Hola, {$v['name']}:\n\nYa tienes disponible la valoración orientativa de tu inmueble en {$v['address']} (ref. {$this->valuation->reference}).\n\n"
            ."Con los datos de Catastro, las estadísticas oficiales de precios y las características que nos has indicado, estimamos que el precio de venta se sitúa entre {$v['min']} y {$v['max']}.\n\n"
            .($this->link !== null
                ? "Tienes el informe completo adjunto en PDF. También puedes consultarlo y descargarlo hasta el {$v['until']}:\n{$this->link}\n\n"
                : "Tienes el informe completo adjunto en PDF.\n\n")
            .($v['low'] ? "En tu zona hay pocos datos de operaciones recientes, por eso el intervalo es amplio.\n\n" : '')
            ."Es una estimación informativa, no una tasación oficial. El estado real de la vivienda, las reformas, la altura, la orientación y la luz, las vistas o la situación de la comunidad pueden cambiar el precio al que conviene ofrecerla.\n\n"
            .'Si estás pensando en vender, podemos revisar contigo las características de la vivienda y preparar una valoración personalizada.'.$booking."\n\n"
            .'¿Te viene mejor que te llamemos por la mañana o por la tarde? Responde al WhatsApp que te hemos enviado o llámanos al '.ReportData::PHONE.'.'.$footer;
    }

    /** Footer of Liberu's (transactional) e-mails, annex B.0. */
    public static function footer(?string $reference): string
    {
        return 'CLARTO · Lo complejo, claro. · '.ReportData::PHONE.' · WhatsApp '.ReportData::WHATSAPP.' · '.ReportData::EMAIL."\n"
            ."Te enviamos este correo porque solicitaste una valoración en clarto.es (ref. {$reference}).\n"
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
            'booking' => $this->valuation->visitUrl('correo1'),
            'until' => ($this->linkExpires ?? now()->addDays(ReportLinks::days()))->copy()->timezone('Europe/Madrid')->format('d/m/Y'),
            'min' => isset($r['intervalo']['min']) ? ReportData::eur($r['intervalo']['min']) : '',
            'max' => isset($r['intervalo']['max']) ? ReportData::eur($r['intervalo']['max']) : '',
            'low' => ($r['suficiencia'] ?? null) === 'baja',
            'reason' => trim((string) $this->run->message),
        ];
    }

    public function envelope(): Envelope
    {
        $from = (string) config('mail.from.address');   // the panel's outgoing mailbox (info@clarto.es); only the name changes

        return new Envelope(from: $from !== '' ? new \Illuminate\Mail\Mailables\Address($from, 'CLARTO') : null, subject: $this->subjectLine());
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
