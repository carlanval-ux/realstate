@php
    /** Private answer page of a collaborator (SearchRequestLinkController). Inline CSS (nonce), no external resources. */
    use App\SearchRequests\SearchRequest;
    use App\SearchRequests\SearchRequestMessages;
    use App\SearchRequests\SearchRequestResponse;
    $p = fn (string $k, array $r = []) => __('solicitudes.pagina.'.$k, $r);
    $f = fn (string $k) => __('solicitudes.campo.'.$k);
    $old = $old ?? [];
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive">
<meta name="referrer" content="no-referrer">
<title>{{ $request ? $request->reference.' · ' : '' }}CLARTO</title>
<style nonce="{{ $nonce }}">
    :root { --petrol: #173D38; --terra: #BD7968; --ink: #1d2624; --muted: #5f6b68; --line: #dfe5e3; --bg: #f6f7f6; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: var(--ink); background: var(--bg); line-height: 1.5; }
    header { background: var(--petrol); color: #fff; padding: 18px 16px; }
    header .wrap { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; flex-wrap: wrap; }
    .brand { font-weight: 800; letter-spacing: .08em; }
    .wrap { max-width: 760px; margin: 0 auto; }
    main { padding: 16px; }
    .card { background: #fff; border: 1px solid var(--line); border-radius: 10px; padding: 18px; margin-bottom: 16px; }
    h1 { font-size: 1.3rem; margin: 0 0 4px; }
    h2 { font-size: 1.05rem; margin: 0 0 12px; }
    .meta { color: var(--muted); font-size: .9rem; }
    .due { display: inline-block; margin-top: 8px; padding: 4px 10px; border-radius: 999px; background: #f3e4df; color: #7a3f31; font-weight: 600; font-size: .9rem; }
    .summary { white-space: pre-line; }
    label { display: block; font-weight: 600; margin: 12px 0 4px; font-size: .95rem; }
    input[type=text], input[type=number], textarea, select { width: 100%; padding: 10px; border: 1px solid #c7d0cd; border-radius: 8px; font: inherit; }
    textarea { min-height: 90px; }
    .kinds label { font-weight: 400; display: flex; gap: 8px; align-items: center; margin: 6px 0; }
    button { margin-top: 16px; background: var(--petrol); color: #fff; border: 0; border-radius: 8px; padding: 12px 18px; font: inherit; font-weight: 700; cursor: pointer; }
    .ok { border-color: #9cc8b5; background: #eef7f2; }
    .err { border-color: #e2a99b; background: #fbefec; }
    .resp { border-top: 1px solid var(--line); padding-top: 10px; margin-top: 10px; }
    .resp:first-child { border-top: 0; margin-top: 0; padding-top: 0; }
    .tag { font-size: .8rem; background: #e7efed; color: var(--petrol); border-radius: 6px; padding: 2px 8px; }
    a { color: var(--petrol); }
    .help { color: var(--muted); font-size: .85rem; }
</style>
</head>
<body>
<header><div class="wrap"><span class="brand">CLARTO</span><span>{{ $p('titulo') }}</span></div></header>
<main><div class="wrap">
@if ($estado !== 'activo')
    <div class="card err">
        <h1>{{ $p('estado_'.$estado) }}</h1>
        @if ($request)<p class="meta">{{ $request->reference }} · {{ $request->title }}</p>@endif
        <p>{{ $p('estado_'.$estado.'_texto') }}</p>
    </div>
@else
    @if ($notice === 'enviada')<div class="card ok"><strong>{{ __('solicitudes.aviso.response_saved') }}</strong></div>@endif
    @if (! empty($problems))<div class="card err">@foreach ($problems as $e)<div>{{ $e }}</div>@endforeach</div>@endif

    <div class="card">
        <p class="meta">{{ $p('hola', ['nombre' => $collaborator->firstName()]) }}</p>
        <h1>{{ $request->title }}</h1>
        <div class="meta">{{ $f('reference') }}: <strong>{{ $request->reference }}</strong></div>
        <div class="due">{{ $f('respond_by') }}: {{ $request->respond_by->format('d/m/Y') }}</div>
        <p class="summary">{{ SearchRequestMessages::summary($request, app()->getLocale()) }}</p>
        @foreach (array_values((array) $request->attachments) as $i => $path)
            <div><a href="/s/{{ $codigo }}/adjunto/{{ $i }}" rel="noopener">{{ $request->attachment_names[$path] ?? basename($path) }}</a></div>
        @endforeach
    </div>

    @if ($responses->isNotEmpty())
    <div class="card">
        <h2>{{ __('solicitudes.seccion.my_responses') }}</h2>
        @foreach ($responses as $r)
            <div class="resp">
                <span class="tag">{{ $r->kindLabel() }} · v{{ $r->version }}</span>
                <span class="meta">{{ $r->created_at->timezone(SearchRequest::timezone($request->team_id))->format('d/m/Y H:i') }}</span>
                @if ($r->description)<div>{{ $r->description }}</div>@endif
                <div class="meta">{{ collect([$r->location, $r->price ? number_format($r->price, 0, ',', '.').' €' : null, $r->estimated_time])->filter()->implode(' · ') }}</div>
                @if (! empty($r->photos))<div class="meta">{{ $f('photos') }}: {{ count($r->photos) }}</div>@endif
            </div>
        @endforeach
    </div>
    @endif

    <form class="card" method="post" action="/s/{{ $codigo }}" enctype="multipart/form-data">
        <h2>{{ $p('responder') }}</h2>
        <div class="kinds">
            @foreach (SearchRequestResponse::KINDS as $k)
                <label><input type="radio" name="kind" value="{{ $k }}" required @checked(($old['kind'] ?? '') === $k)> {{ __('solicitudes.respuesta.'.$k) }}</label>
            @endforeach
        </div>
        @php($previous = $responses->whereIn('kind', SearchRequestResponse::PROPOSALS))
        @if ($previous->isNotEmpty())
            <label for="parent_id">{{ $p('completar') }}</label>
            <select id="parent_id" name="parent_id">
                <option value="">{{ $p('nueva_propuesta') }}</option>
                @foreach ($previous as $r)<option value="{{ $r->id }}" @selected(($old['parent_id'] ?? '') == $r->id)>{{ $r->kindLabel() }} · v{{ $r->version }} · {{ Str::limit((string) $r->description, 60) }}</option>@endforeach
            </select>
        @endif
        <label for="description">{{ $f('description') }}</label>
        <textarea id="description" name="description" maxlength="5000">{{ $old['description'] ?? '' }}</textarea>
        <label for="location">{{ $f('location') }}</label>
        <input id="location" type="text" name="location" maxlength="250" value="{{ $old['location'] ?? '' }}">
        <label for="price">{{ $f('price') }}</label>
        <input id="price" type="number" name="price" min="0" value="{{ $old['price'] ?? '' }}">
        <label for="features">{{ $f('features') }}</label>
        <textarea id="features" name="features" maxlength="2000">{{ $old['features'] ?? '' }}</textarea>
        <label for="links">{{ $f('links') }}</label>
        <textarea id="links" name="links" placeholder="https://…">{{ $old['links'] ?? '' }}</textarea>
        <div class="help">{{ $p('enlaces_ayuda') }}</div>
        <label for="photos">{{ $p('archivos') }}</label>
        <input id="photos" type="file" name="photos[]" multiple accept="image/jpeg,image/png,image/webp,application/pdf">
        <div class="help">{{ $p('archivos_ayuda', ['n' => SearchRequestResponse::MAX_PHOTOS]) }}</div>
        <label for="estimated_time">{{ $f('estimated_time') }}</label>
        <input id="estimated_time" type="text" name="estimated_time" maxlength="120" value="{{ $old['estimated_time'] ?? '' }}">
        <button type="submit">{{ $p('enviar') }}</button>
    </form>
    <p class="help">{{ $p('privado') }}</p>
@endif
<p class="meta">Equipo CLARTO</p>
</div></main>
</body>
</html>
