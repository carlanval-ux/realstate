@php($d = $this->data())
<x-filament-panels::page>
    @include('filament.procesos.styles')
    <div class="clp">
        <form class="clp-search" role="search" wire:submit.prevent="$refresh">
            <input type="search" wire:model.live.debounce.400ms="q" placeholder="{{ __('procesos.inicio.buscar') }}" aria-label="{{ __('procesos.inicio.buscar') }}" autofocus>
            @if ($d['assistant'])
                <a class="clp-btn clp-btn--primary" href="{{ $d['assistant'] }}">{{ __('procesos.inicio.preguntar') }}</a>
            @endif
        </form>
        <p class="clp-meta" style="margin-top:.4rem">{{ trans_choice('procesos.inicio.total', $d['total'], ['n' => $d['total']]) }}</p>

        @if ($d['results'] !== null)
            <section class="clp-section" aria-live="polite">
                <h2>{{ __('procesos.inicio.resultados') }} ({{ $d['results']->count() }})
                    <button type="button" class="clp-btn" style="margin-left:.5rem" wire:click="clearFilters">{{ __('procesos.inicio.limpiar') }}</button></h2>
                @forelse ($d['results'] as $v)
                    @if ($loop->first)<div class="clp-grid">@endif
                    @include('filament.procesos.card', ['v' => $v, 'states' => $d['states']])
                    @if ($loop->last)</div>@endif
                @empty
                    <p class="clp-empty">{{ __('procesos.inicio.sin_resultados') }}</p>
                @endforelse
            </section>
        @endif

        <section class="clp-section">
            <h2>{{ __('procesos.inicio.mi_puesto') }}</h2>
            @if (! $d['hasPosition'])
                <p class="clp-empty">{{ __('procesos.inicio.sin_puesto') }}</p>
            @elseif ($d['mine']->isEmpty())
                <p class="clp-empty">{{ __('procesos.inicio.mi_puesto_vacio') }}</p>
            @else
                <div class="clp-grid">@foreach ($d['mine'] as $v) @include('filament.procesos.card', ['v' => $v, 'states' => $d['states']]) @endforeach</div>
            @endif
        </section>

        <section class="clp-section">
            <h2>{{ __('procesos.inicio.favoritos') }}</h2>
            @if ($d['favorites']->isEmpty())
                <p class="clp-empty">{{ __('procesos.inicio.favoritos_vacio') }}</p>
            @else
                <div class="clp-grid">@foreach ($d['favorites'] as $v) @include('filament.procesos.card', ['v' => $v, 'states' => $d['states']]) @endforeach</div>
            @endif
        </section>

        <section class="clp-section">
            <h2>{{ __('procesos.inicio.recientes') }}</h2>
            @if ($d['recent']->isEmpty())
                <p class="clp-empty">{{ __('procesos.inicio.recientes_vacio') }}</p>
            @else
                <div class="clp-grid">@foreach ($d['recent'] as $v) @include('filament.procesos.card', ['v' => $v, 'states' => $d['states']]) @endforeach</div>
            @endif
        </section>

        <section class="clp-section">
            <h2>{{ __('procesos.inicio.por_area') }}</h2>
            <div class="clp-chips">
                @foreach ($d['areas'] as $a)
                    <button type="button" class="clp-chip" aria-pressed="{{ $area === $a['id'] ? 'true' : 'false' }}" wire:click="$set('area', {{ $area === $a['id'] ? 'null' : $a['id'] }})">{{ $a['label'] }} · {{ $a['count'] }}</button>
                @endforeach
            </div>
        </section>
        <section class="clp-section">
            <h2>{{ __('procesos.inicio.por_puesto') }}</h2>
            <div class="clp-chips">
                @forelse ($d['positions'] as $p)
                    <button type="button" class="clp-chip" aria-pressed="{{ $puesto === $p['id'] ? 'true' : 'false' }}" wire:click="$set('puesto', {{ $puesto === $p['id'] ? 'null' : $p['id'] }})">{{ $p['label'] }} · {{ $p['count'] }}</button>
                @empty
                    <p class="clp-empty">—</p>
                @endforelse
            </div>
        </section>
        <section class="clp-section">
            <h2>{{ __('procesos.inicio.por_tarea') }}</h2>
            <div class="clp-chips">
                @forelse ($d['keywords'] as $k)
                    <button type="button" class="clp-chip" aria-pressed="{{ $tarea === $k ? 'true' : 'false' }}" wire:click="$set('tarea', {{ $tarea === $k ? 'null' : \Illuminate\Support\Js::from($k) }})">{{ $k }}</button>
                @empty
                    <p class="clp-empty">—</p>
                @endforelse
            </div>
        </section>
    </div>
</x-filament-panels::page>
