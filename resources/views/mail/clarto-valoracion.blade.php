<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>CLARTO</title>
</head>
<body style="margin:0;padding:0;background:#EBE4D7;font-family:'DM Sans',Arial,Helvetica,sans-serif;color:#132A27;">
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
    <tr><td style="padding:32px 32px 8px;">
        <p style="margin:0 0 6px;font-size:12px;letter-spacing:2px;text-transform:uppercase;color:#9E5847;font-weight:bold;">Estimación orientativa</p>
        <h1 style="margin:0 0 10px;font-family:Manrope,Arial,sans-serif;font-size:26px;line-height:1.2;color:#173D38;">Hola {{ $name }}, aquí tiene su estimación</h1>
        <p style="margin:0;font-size:15px;line-height:1.55;color:#526663;">{{ $data->displayAddress() }} · {{ mb_convert_case($data->municipality(), MB_CASE_TITLE) }}</p>
    </td></tr>
    <tr><td style="padding:18px 32px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F5F1E9;border-left:5px solid #BD7968;">
            <tr><td style="padding:20px 24px;">
                <p style="margin:0;font-size:11px;letter-spacing:1.5px;text-transform:uppercase;color:#9E5847;font-weight:bold;">Precio estimado de cierre</p>
                <p style="margin:6px 0 4px;font-family:Manrope,Arial,sans-serif;font-size:36px;font-weight:800;color:#173D38;">{{ \App\Valuations\ReportData::eur($r['precio_central']) }}</p>
                <p style="margin:0;font-size:14px;color:#132A27;"><strong>Intervalo orientativo:</strong> {{ \App\Valuations\ReportData::eur($r['intervalo']['min']) }} – {{ \App\Valuations\ReportData::eur($r['intervalo']['max']) }}</p>
            </td></tr>
        </table>
    </td></tr>
    <tr><td style="padding:4px 32px 8px;font-size:15px;line-height:1.6;">
        <p style="margin:0 0 16px;">Le adjuntamos el informe en PDF. También puede descargarlo desde este enlace privado, válido durante {{ (int) config('valoraciones.informe_dias', 30) }} días:</p>
        <p style="margin:0 0 22px;"><a href="{{ $link }}" style="display:inline-block;background:#173D38;color:#FFFFFF;text-decoration:none;padding:13px 22px;font-weight:bold;">Descargar el informe</a></p>
        <p style="margin:0 0 8px;color:#526663;font-size:13px;">Es una estimación orientativa calculada con datos públicos: no es una tasación oficial ni sustituye la visita de un profesional.</p>
    </td></tr>
    @else
    <tr><td style="padding:32px 32px 8px;font-size:15px;line-height:1.6;">
        <h1 style="margin:0 0 12px;font-family:Manrope,Arial,sans-serif;font-size:24px;line-height:1.25;color:#173D38;">Hola {{ $name }}, hemos recibido su solicitud</h1>
        <p style="margin:0 0 14px;">Su solicitud de valoración de <strong>{{ $data->displayAddress() }}</strong> (ref. {{ $valuation->reference }}) está registrada. Con los datos disponibles no podemos darle una estimación automática fiable, así que nuestro equipo la revisará personalmente y le contactará en breve.</p>
    </td></tr>
    @endif
    <tr><td style="padding:16px 32px 28px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#173D38;">
            <tr><td style="padding:22px 24px;color:#FFFFFF;">
                <p style="margin:0 0 6px;font-family:Manrope,Arial,sans-serif;font-size:19px;font-weight:800;">¿Vende su vivienda? Le acompañamos de principio a fin.</p>
                <p style="margin:0 0 14px;font-size:14px;line-height:1.5;color:#C9D3D1;">Gestionamos la venta de su inmueble —valoración, documentación, difusión y firma—, trabajamos en toda España y aplicamos tecnología actual en cada paso.</p>
                <p style="margin:0 0 4px;font-size:15px;font-weight:bold;color:#D99B8C;">Solicitar una valoración personalizada con CLARTO</p>
                <p style="margin:0;font-size:14px;color:#FFFFFF;">{{ \App\Valuations\ReportData::PHONE }} · WhatsApp {{ \App\Valuations\ReportData::WHATSAPP }} · <a href="mailto:{{ \App\Valuations\ReportData::EMAIL }}" style="color:#FFFFFF;">{{ \App\Valuations\ReportData::EMAIL }}</a></p>
            </td></tr>
        </table>
    </td></tr>
    <tr><td style="padding:0 32px 26px;font-size:12px;color:#526663;">
        <em style="font-family:'Source Serif 4',Georgia,serif;color:#9E5847;">Lo complejo, claro.</em> · <a href="https://clarto.es" style="color:#526663;">clarto.es</a> · Ref. {{ $valuation->reference }}
    </td></tr>
</table>
</td></tr>
</table>
</body>
</html>
