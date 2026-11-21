{{-- History modal: $events (append-only), $versions. --}}
@include('filament.procesos.styles')
<div class="clp">
    <h3 style="font-weight:600;margin-bottom:.5rem">{{ __('procesos.secciones.cambios') }}</h3>
    <table class="clp-table">
        <thead><tr><th>{{ __('procesos.campos.version') }}</th><th>{{ __('procesos.campos.status') }}</th><th>{{ __('procesos.campos.author') }}</th><th>{{ __('procesos.campos.published_at') }}</th></tr></thead>
        <tbody>
            @foreach ($versions as $ver)
                <tr><td><a href="{{ \App\Processes\ProcessLinks::version($ver) }}" style="text-decoration:underline">{{ $ver->number ? 'v'.$ver->number : __('procesos.estados_version.'.$ver->status) }}</a></td>
                    <td>{{ __('procesos.estados_version.'.$ver->status) }}</td><td>{{ $ver->author?->name ?? '—' }}</td><td>{{ $ver->published_at?->format('d/m/Y H:i') ?? '—' }}</td></tr>
            @endforeach
        </tbody>
    </table>
    <h3 style="font-weight:600;margin:1rem 0 .5rem">{{ __('procesos.ver.historial') }}</h3>
    <table class="clp-table">
        <thead><tr><th>{{ __('procesos.ver.fecha') }}</th><th>{{ __('procesos.ver.quien') }}</th><th>{{ __('procesos.ver.que') }}</th><th>{{ __('procesos.campos.note') }}</th></tr></thead>
        <tbody>
            @foreach ($events as $e)
                <tr><td>{{ $e->created_at?->format('d/m/Y H:i') }}</td><td>{{ $e->user?->name ?? '—' }}</td><td>{{ __('procesos.eventos.'.$e->type) }}</td>
                    <td>{{ $e->note }}@if ($e->data)<div class="clp-meta" style="word-break:break-word">{{ \Illuminate\Support\Str::limit(json_encode($e->data, JSON_UNESCAPED_UNICODE), 300) }}</div>@endif</td></tr>
            @endforeach
        </tbody>
    </table>
</div>
