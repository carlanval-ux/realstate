@php
    /** Public report page on valoraciones.clarto.es (InformeEnlaceController). No external resources: inline CSS (nonce), logo as data URI. */
    $titles = [
        'activo' => 'Informe de valoración',
        'caducado' => 'Este enlace ha caducado',
        'revocado' => 'Este enlace ya no está disponible',
        'desconocido' => 'Enlace no válido',
        'limite' => 'Demasiadas peticiones',
    ];
    $wa = 'https://wa.me/34'.preg_replace('/\D/', '', $whatsapp);
    $tel = 'tel:+34'.preg_replace('/\D/', '', $phone);
    if ($estado === 'activo') {
        $valuation = $link->request;
        $run = $link->run;
        $r = (array) $run->result;
        $zone = mb_convert_case((string) ($valuation->catastro['municipio'] ?? $valuation->property['municipio'] ?? ''), MB_CASE_TITLE);
        $madrid = fn ($date) => $date->copy()->timezone('Europe/Madrid')->format('d/m/Y');
    }
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive">
<meta name="referrer" content="no-referrer">
<meta property="og:title" content="Informe de valoración · CLARTO">
<meta property="og:description" content="Valoración orientativa de tu inmueble.">
<title>{{ $titles[$estado] }} · CLARTO</title>
<style nonce="{{ $nonce }}">
    *{box-sizing:border-box}
    body{margin:0;background:#EBE4D7;color:#132A27;font:16px/1.55 system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif}
    header{background:#173D38;padding:18px 20px}
    header img{display:block;width:150px;height:auto}
    main{max-width:560px;margin:0 auto;padding:20px 16px 32px}
    .card{background:#fff;padding:24px 20px}
    .eyebrow{margin:0 0 6px;font-size:12px;letter-spacing:2px;text-transform:uppercase;color:#9E5847;font-weight:700}
    h1{margin:0 0 10px;font-size:26px;line-height:1.2;color:#173D38}
    p{margin:0 0 14px}
    .muted{color:#526663;font-size:14px}
    .range{background:#F5F1E9;border-left:5px solid #BD7968;padding:14px 18px;margin:0 0 16px}
    .range strong{display:block;font-size:24px;line-height:1.25;color:#173D38}
    .btn{display:block;text-align:center;text-decoration:none;font-weight:700;padding:14px 18px;margin:0 0 10px}
    .btn-main{background:#173D38;color:#fff}
    .btn-alt{background:#fff;color:#173D38;border:2px solid #173D38}
    .contact{margin-top:18px;font-size:14px;color:#526663}
    .contact a{color:#173D38}
    footer{text-align:center;font-size:12px;color:#526663;padding:0 16px 24px}
</style>
</head>
<body>
<header><img src="{{ $logo }}" alt="CLARTO" width="150"></header>
<main>
    <div class="card">
    @if ($estado === 'activo')
        <p class="eyebrow">Valoración orientativa</p>
        <h1>{{ $titles['activo'] }}</h1>
        <p>Inmueble en {{ $zone }} · Ref. {{ $valuation->reference }}</p>
        @if (isset($r['intervalo']['min'], $r['intervalo']['max']))
        <div class="range"><span class="muted">Intervalo estimado</span><strong>entre {{ \App\Valuations\ReportData::eur($r['intervalo']['min']) }} y {{ \App\Valuations\ReportData::eur($r['intervalo']['max']) }}</strong></div>
        @endif
        <a class="btn btn-main" href="/r/{{ $codigo }}/informe.pdf">Ver el informe</a>
        <a class="btn btn-alt" href="/r/{{ $codigo }}/informe.pdf?descargar=1">Descargar el PDF</a>
        <p class="muted">Cálculo del {{ $madrid($run->created_at) }}. Puedes consultar y descargar el informe hasta el {{ $madrid($link->expires_at) }}.</p>
        <p class="muted">Es una estimación informativa, no una tasación oficial. Si estás pensando en vender, podemos revisar contigo las características de la vivienda y preparar una valoración personalizada.</p>
    @elseif ($estado === 'caducado')
        <h1>{{ $titles['caducado'] }}</h1>
        <p>El enlace del informe estaba disponible hasta el {{ $link->expires_at->copy()->timezone('Europe/Madrid')->format('d/m/Y') }}.</p>
        <p>Escríbenos por WhatsApp o llámanos y te enviamos uno nuevo.</p>
    @elseif ($estado === 'revocado')
        <h1>{{ $titles['revocado'] }}</h1>
        <p>Este enlace se ha desactivado. Si necesitas el informe, escríbenos por WhatsApp o llámanos y te ayudamos.</p>
    @elseif ($estado === 'limite')
        <h1>{{ $titles['limite'] }}</h1>
        <p>Espera un minuto y vuelve a intentarlo.</p>
    @else
        <h1>{{ $titles['desconocido'] }}</h1>
        <p>Comprueba que el enlace esté completo, tal como te llegó por WhatsApp o correo. Si sigue sin funcionar, escríbenos y te enviamos uno nuevo.</p>
    @endif
        <p class="contact">CLARTO · <a href="{{ $tel }}">{{ $phone }}</a> · <a href="{{ $wa }}">WhatsApp {{ $whatsapp }}</a> · <a href="mailto:{{ $email }}">{{ $email }}</a></p>
    </div>
</main>
<footer>CLARTO · Lo complejo, claro.</footer>
</body>
</html>
