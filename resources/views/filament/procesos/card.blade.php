{{-- One process card: $v (current visible version), $states (personal states by process id). --}}
@php($p = $v->process)
<article class="clp-card" wire:key="card-{{ $v->id }}">
    <div class="clp-meta">
        <strong>{{ $p->code }}</strong>
        <span>{{ $p->area?->name }}</span>
        <span class="clp-tag">{{ __('procesos.formatos.'.$p->kind) }}</span>
        @if (str_starts_with($v->title, __('procesos.demostracion')))<span class="clp-tag clp-tag--demo">{{ __('procesos.demostracion') }}</span>@endif
        @if ($p->visibility === 'restringido')<span class="clp-tag">{{ __('procesos.visibilidades.restringido') }}</span>@endif
        <button type="button" class="clp-star" style="margin-left:auto" wire:click="toggleFavorite({{ $p->id }})"
            aria-pressed="{{ ($states[$p->id] ?? null)?->favorite ? 'true' : 'false' }}" aria-label="{{ __('procesos.ver.favorito') }}" title="{{ __('procesos.ver.favorito') }}">★</button>
    </div>
    <h3><a href="{{ \App\Processes\ProcessLinks::process($p) }}">{{ $v->title }}</a></h3>
    @if ($v->summary)<p>{{ $v->summary }}</p>@endif
    <div class="clp-meta">
        <span>{{ __('procesos.campos.owner') }}: {{ $p->owner?->name ?? __('procesos.gestion.sin_asignar') }}</span>
        <span>{{ __('procesos.campos.next_review_on') }}: {{ $p->next_review_on?->format('d/m/Y') ?? '—' }}</span>
    </div>
</article>
