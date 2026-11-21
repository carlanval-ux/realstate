<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>CLARTO</title>
</head>
<body style="margin:0;padding:0;background:#EBE4D7;font-family:'DM Sans',Arial,Helvetica,sans-serif;color:#132A27;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;">{{ $hasReport ? 'Consulte el intervalo estimado y cómo afinarlo con una visita a la vivienda.' : 'Un asesor la revisará personalmente para darle una cifra fiable.' }}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#EBE4D7;padding:24px 12px;">
<tr><td align="center">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#FFFFFF;">
    <tr><td style="background:#173D38;padding:22px 32px;">
        @isset($message)
        <img src="{{ $message->embed(resource_path('valoraciones/logo-negativo.jpg')) }}" width="190" alt="CLARTO" style="display:block;border:0;">
        @else
        <span style="font-family:Manrope,Arial,sans-serif;font-size:24px;font-weight:800;letter-spacing:3px;color:#F5F1E9;">CLARTO</span>
        @endisset
    </td></tr>
    @if ($hasReport)
    <tr><td style="padding:32px 32px 8px;font-size:15px;line-height:1.6;">
        <p style="margin:0 0 6px;font-size:12px;letter-spacing:2px;text-transform:uppercase;color:#9E5847;font-weight:bold;">Valoración orientativa</p>
        <p style="margin:0 0 14px;">Hola, {{ $v['name'] }}:</p>
        <p style="margin:0 0 14px;">Ya tiene la valoración orientativa de su inmueble en <strong>{{ $v['address'] }}</strong> (ref. {{ $valuation->reference }}).</p>
        <p style="margin:0;">Con los datos de Catastro, las estadísticas oficiales de precios y las características que nos ha indicado, estimamos que el precio de venta se sitúa:</p>
    </td></tr>
    <tr><td style="padding:14px 32px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F5F1E9;border-left:5px solid #BD7968;">
            <tr><td style="padding:18px 24px;">
                <p style="margin:0;font-size:11px;letter-spacing:1.5px;text-transform:uppercase;color:#9E5847;font-weight:bold;">Intervalo estimado</p>
                <p style="margin:6px 0 0;font-family:Manrope,Arial,sans-serif;font-size:28px;font-weight:800;color:#173D38;">entre {{ $v['min'] }} y {{ $v['max'] }}</p>
            </td></tr>
        </table>
    </td></tr>
    <tr><td style="padding:4px 32px 8px;font-size:15px;line-height:1.6;">
        <p style="margin:0 0 14px;">Tiene el informe completo adjunto en PDF. También puede <a href="{{ $link }}" style="color:#173D38;">descargarlo aquí</a> hasta el {{ $v['until'] }}.</p>
        @if ($v['low'])
        <p style="margin:0 0 14px;">En su zona hay pocos datos de operaciones recientes, por eso el intervalo es amplio.</p>
        @endif
        <p style="margin:0 0 14px;color:#526663;font-size:14px;">Es una estimación automática, no una tasación oficial. El estado real de la vivienda, las reformas, la altura, la orientación y la luz, las vistas o la situación de la comunidad pueden cambiar el precio al que conviene ofrecerla.</p>
        <p style="margin:0 0 18px;">Si está pensando en vender, un asesor de CLARTO puede visitar la vivienda y concretar con usted una valoración personalizada. No tiene coste ni le compromete a nada.</p>
    </td></tr>
    @else
    <tr><td style="padding:32px 32px 8px;font-size:15px;line-height:1.6;">
        <p style="margin:0 0 14px;">Hola, {{ $v['name'] }}:</p>
        <p style="margin:0 0 14px;">Hemos recibido su solicitud de valoración del inmueble en <strong>{{ $v['address'] }}</strong> (ref. {{ $valuation->reference }}).</p>
        @if ($v['reason'] !== '')
        <p style="margin:0 0 14px;">{{ $v['reason'] }}</p>
        @endif
        <p style="margin:0 0 14px;">Por eso, un asesor de CLARTO la revisará personalmente y le contactará en breve.</p>
        <p style="margin:0 0 18px;">Si lo prefiere, puede elegir ya el día para que visitemos la vivienda:</p>
    </td></tr>
    @endif
    @if ($v['booking'] !== null)
    <tr><td style="padding:0 32px 18px;">
        <a href="{{ $v['booking'] }}" style="display:inline-block;background:#BD7968;color:#FFFFFF;text-decoration:none;padding:14px 24px;font-weight:bold;font-size:15px;">Solicitar valoración personalizada</a>
    </td></tr>
    @endif
    <tr><td style="padding:0 32px 18px;font-size:15px;line-height:1.6;">
        <p style="margin:0 0 14px;">{{ $hasReport ? '¿Prefiere hablarlo antes? Responda al WhatsApp que le hemos enviado o llámenos al' : 'También puede responder al WhatsApp que le hemos enviado o llamarnos al' }} {{ \App\Valuations\ReportData::PHONE }}.</p>
        <p style="margin:0;">Un saludo,<br>{{ \App\Valuations\ValuationReportMail::SIGNATURE }}</p>
    </td></tr>
    <tr><td style="padding:8px 32px 28px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#173D38;">
            <tr><td style="padding:22px 24px;color:#FFFFFF;">
                <p style="margin:0 0 6px;font-size:11px;letter-spacing:2px;font-weight:bold;color:#D99B8C;">{{ \App\Valuations\ReportData::SALES_EYEBROW }}</p>
                <p style="margin:0 0 8px;font-family:Manrope,Arial,sans-serif;font-size:20px;line-height:1.25;font-weight:800;">{{ \App\Valuations\ReportData::SALES_TITLE }}</p>
                <p style="margin:0 0 14px;font-family:'Source Serif 4',Georgia,serif;font-style:italic;font-size:15px;line-height:1.5;color:#D99B8C;">{{ \App\Valuations\ReportData::SALES_LEAD }}</p>
                @foreach (\App\Valuations\ReportData::SALES_PILLARS as [$icon, $title, $text])
                <p style="margin:0 0 10px;font-size:14px;line-height:1.5;color:#C9D3D1;"><strong style="color:#FFFFFF;">{{ $title }}.</strong> {{ $text }}</p>
                @endforeach
            </td></tr>
        </table>
    </td></tr>
    <tr><td style="padding:0 32px 26px;font-size:12px;line-height:1.5;color:#526663;">
        {!! nl2br(e(\App\Valuations\ValuationReportMail::footer($valuation->reference))) !!}
    </td></tr>
</table>
</td></tr>
</table>
</body>
</html>
