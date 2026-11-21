{{-- One tab of «Gestión de procesos»: $tab, $rows. --}}
@include('filament.procesos.styles')
@php($link = fn ($process) => \App\Processes\ProcessLinks::process($process))
<div class="clp" style="overflow-x:auto">
    @switch($tab)
        @case('revision')
            @if (! $rows)<p class="clp-empty">{{ __('procesos.gestion.nada') }}</p>@else
            <table class="clp-table">
                <thead><tr><th>{{ __('procesos.campos.code') }}</th><th>{{ __('procesos.campos.title') }}</th><th>{{ __('procesos.campos.status') }}</th><th>{{ __('procesos.campos.author') }}</th><th>{{ __('procesos.gestion.desde') }}</th></tr></thead>
                <tbody>@foreach ($rows as $v)
                    <tr><td>{{ $v->process->code }}</td><td><a href="{{ \App\Processes\ProcessLinks::version($v) }}" style="text-decoration:underline">{{ $v->title }}</a></td>
                        <td><span class="clp-tag {{ $v->status === 'en_revision' ? 'clp-tag--warn' : '' }}">{{ __('procesos.estados_version.'.$v->status) }}</span></td>
                        <td>{{ $v->author?->name ?? '—' }}</td><td>{{ ($v->submitted_at ?? $v->updated_at)?->format('d/m/Y') }}</td></tr>
                @endforeach</tbody>
            </table>@endif
            @break
        @case('vencimientos')
            @if (! $rows)<p class="clp-empty">{{ __('procesos.gestion.nada') }}</p>@else
            <table class="clp-table">
                <thead><tr><th>{{ __('procesos.campos.code') }}</th><th>{{ __('procesos.campos.title') }}</th><th>{{ __('procesos.campos.owner') }}</th><th>{{ __('procesos.campos.next_review_on') }}</th></tr></thead>
                <tbody>@foreach ($rows as $p)
                    <tr><td>{{ $p->code }}</td><td><a href="{{ $link($p) }}" style="text-decoration:underline">{{ $p->currentVersion?->title }}</a></td><td>{{ $p->owner?->name ?? '—' }}</td>
                        <td><span class="clp-tag {{ $p->next_review_on?->isPast() ? 'clp-tag--warn' : '' }}">{{ $p->next_review_on?->format('d/m/Y') }} · {{ $p->next_review_on?->isPast() ? __('procesos.gestion.vencida') : __('procesos.gestion.proxima') }}</span></td></tr>
                @endforeach</tbody>
            </table>@endif
            @break
        @case('responsables')
            @if (! $rows)<p class="clp-empty">{{ __('procesos.gestion.nada') }}</p>@else
            <table class="clp-table">
                <thead><tr><th>{{ __('procesos.campos.code') }}</th><th>{{ __('procesos.campos.title') }}</th><th>{{ __('procesos.campos.owner') }}</th><th>{{ __('procesos.campos.approver') }}</th><th></th></tr></thead>
                <tbody>@foreach ($rows as $p)
                    <tr><td>{{ $p->code }}</td><td><a href="{{ $link($p) }}" style="text-decoration:underline">{{ ($p->currentVersion ?? $p->openVersion)?->title }}</a></td>
                        <td>{{ $p->owner ? $p->owner->name.' ('.__('procesos.gestion.no_activo').')' : __('procesos.gestion.sin_asignar') }}</td>
                        <td>{{ $p->approver?->name ?? __('procesos.gestion.sin_asignar') }}</td>
                        <td>{{ ($page->assignAction)(['process' => $p->getKey()]) }}</td></tr>
                @endforeach</tbody>
            </table>@endif
            @break
        @case('dudas')
            @if (! $rows)<p class="clp-empty">{{ __('procesos.gestion.nada') }}</p>@else
            <table class="clp-table">
                <thead><tr><th>{{ __('procesos.ver.fecha') }}</th><th>{{ __('procesos.campos.code') }}</th><th>{{ __('procesos.campos.text') }}</th><th>{{ __('procesos.ver.quien') }}</th><th>{{ __('procesos.campos.status') }}</th><th></th></tr></thead>
                <tbody>@foreach ($rows as $f)
                    @php($r = $f->improvementRequest)
                    <tr><td>{{ $f->created_at?->format('d/m/Y') }}</td>
                        <td>@if ($f->process)<a href="{{ $f->version ? \App\Processes\ProcessLinks::version($f->version, $f->step_key ? 'paso-'.$f->step_key : null) : $link($f->process) }}" style="text-decoration:underline">{{ $f->process->code }}</a>@else — @endif
                            <span class="clp-meta">{{ $f->source === 'asistente' ? __('procesos.gestion.desde_asistente') : '' }}</span></td>
                        <td style="max-width:28rem">{{ \Illuminate\Support\Str::limit(strtok((string) $r?->description, "\n"), 220) }}</td>
                        <td>{{ $r?->user?->name ?? '—' }}</td>
                        <td>{{ \App\Models\ImprovementRequest::statusLabel($r?->status) }}</td>
                        <td>@if ($r){{ ($page->respondAction)(['request' => $r->getKey()]) }}@endif</td></tr>
                @endforeach</tbody>
            </table>@endif
            @break
        @case('procesos')
            <table class="clp-table">
                <thead><tr><th>{{ __('procesos.campos.code') }}</th><th>{{ __('procesos.campos.title') }}</th><th>{{ __('procesos.campos.status') }}</th><th>{{ __('procesos.campos.version') }}</th><th>{{ __('procesos.campos.owner') }}</th><th>{{ __('procesos.campos.visibility') }}</th><th>{{ __('procesos.gestion.lecturas') }}</th></tr></thead>
                <tbody>@foreach ($rows['processes'] as $p)
                    <tr><td>{{ $p->code }}</td><td><a href="{{ $link($p) }}" style="text-decoration:underline">{{ ($p->currentVersion ?? $p->openVersion)?->title ?? '—' }}</a>
                            @if ($p->openVersion)<span class="clp-tag">{{ __('procesos.estados_version.'.$p->openVersion->status) }}</span>@endif</td>
                        <td>{{ __('procesos.estados_proceso.'.$p->status) }}</td><td>{{ $p->currentVersion?->number ? 'v'.$p->currentVersion->number : '—' }}</td>
                        <td>{{ $p->owner?->name ?? '—' }}</td><td>{{ __('procesos.visibilidades.'.$p->visibility) }}{{ $p->ai_excluded ? ' · '.__('procesos.gestion.sin_ia') : '' }}</td>
                        <td>{{ $rows['reads'][$p->current_version_id] ?? 0 }}</td></tr>
                @endforeach</tbody>
            </table>
            <p class="clp-meta" style="margin-top:.5rem">{{ __('procesos.gestion.lecturas_ayuda') }}</p>
            @break
        @case('mapa')
            <table class="clp-table">
                <thead><tr><th>{{ __('procesos.campos.code') }}</th><th>{{ __('procesos.campos.title') }}</th><th>{{ __('procesos.gestion.prioridad') }}</th><th>{{ __('procesos.campos.status') }}</th></tr></thead>
                <tbody>@foreach ($rows as $e)
                    <tr><td>{{ $e->key }}</td><td>{{ $e->title }}<div class="clp-meta">{{ $e->priority_reason }}</div></td><td>{{ __('procesos.prioridades.'.$e->priority) }}</td>
                        <td>@if ($e->process)<a href="{{ $link($e->process) }}" style="text-decoration:underline">{{ __('procesos.estados_proceso.'.$e->process->status) }}</a>@else{{ __('procesos.mapa_estados.'.$e->status) }}@endif</td></tr>
                @endforeach</tbody>
            </table>
            @break
        @case('recomendaciones')
            <p class="clp-empty">{{ __('procesos.gestion.recomendaciones_pendiente') }}</p>
            @break
    @endswitch
</div>
