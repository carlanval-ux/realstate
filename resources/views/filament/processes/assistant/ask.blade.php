<x-filament-panels::page>
    <style>
        .cl-ask { display: grid; gap: 1.25rem; grid-template-columns: minmax(0, 1fr); }
        @media (min-width: 1024px) { .cl-ask { grid-template-columns: minmax(0, 1fr) 18rem; } }
        .cl-ask__box { padding: 1rem; border-radius: .75rem; border: 1px solid rgba(127,127,127,.25); }
        .cl-ask__input { width: 100%; padding: .6rem .75rem; border-radius: .5rem; border: 1px solid rgba(127,127,127,.35); background: transparent; font-size: .95rem; }
        .cl-ask__answer { white-space: pre-line; line-height: 1.55; }
        .cl-ask__links a, .cl-ask__history button { color: rgb(var(--primary-600, 37 99 235)); text-decoration: underline; text-align: left; }
        .cl-ask__muted { font-size: .8rem; opacity: .7; }
        .cl-ask__row { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; }
    </style>
    <div class="cl-ask">
        <div style="display:flex;flex-direction:column;gap:1rem">
            <form wire:submit="ask" class="cl-ask__box" style="display:flex;flex-direction:column;gap:.6rem">
                <label for="cl-ask-q" style="font-weight:600">{{ __('asistente.pregunta') }}</label>
                <textarea id="cl-ask-q" class="cl-ask__input" rows="3" wire:model="question" maxlength="{{ \App\Processes\Assistant\Assistant::MAX_QUESTION }}" placeholder="{{ __('asistente.placeholder') }}"></textarea>
                @error('question') <span style="color:#dc2626;font-size:.85rem">{{ $message }}</span> @enderror
                <div class="cl-ask__row">
                    <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="ask,askWithin">{{ __('asistente.preguntar') }}</x-filament::button>
                    <span wire:loading wire:target="ask,askWithin" class="cl-ask__muted">{{ __('asistente.pensando') }}</span>
                </div>
                <p class="cl-ask__muted">{{ __('asistente.aviso_datos') }}</p>
            </form>

            @if ($query = $this->current())
                @php($v = $this->presentation($query))
                <section class="cl-ask__box" aria-live="polite" style="display:flex;flex-direction:column;gap:.75rem">
                    <p class="cl-ask__muted">{{ $query->question }}</p>
                    @if ($v['answer'] === null)
                        <p>{{ __('asistente.respuestas.sin_acceso') }}</p>
                    @else
                        <div class="cl-ask__answer">{{ $v['answer'] }}</div>
                    @endif

                    @if ($v['options'] !== [])
                        <div class="cl-ask__row">
                            @foreach ($v['options'] as $option)
                                <x-filament::button color="gray" size="sm" wire:click="askWithin({{ $option['version_id'] }})">{{ $option['label'] }}</x-filament::button>
                            @endforeach
                        </div>
                    @endif

                    @if ($v['answer'] !== null && $v['links'] !== [])
                        <div class="cl-ask__links">
                            <strong style="font-size:.85rem">{{ __('asistente.fuentes') }}</strong>
                            <ul style="margin:.25rem 0 0 1rem;list-style:disc">
                                @foreach ($v['links'] as $link)
                                    <li><a href="{{ $link['url'] }}">{{ $link['label'] }}</a></li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if (in_array($query->outcome, ['sin_documentacion', 'respondida', 'error'], true))
                        <div style="display:flex;flex-direction:column;gap:.4rem;border-top:1px solid rgba(127,127,127,.2);padding-top:.75rem">
                            @if ($query->improvement_request_id)
                                <p class="cl-ask__muted">{{ __('asistente.duda.ya_enviada') }}</p>
                            @else
                                <label for="cl-ask-note" class="cl-ask__muted">{{ $query->outcome === 'sin_documentacion' ? __('asistente.duda.invitacion') : __('asistente.duda.opcional') }}</label>
                                <textarea id="cl-ask-note" class="cl-ask__input" rows="2" wire:model="note" maxlength="2000"></textarea>
                                <div><x-filament::button size="sm" :color="$query->outcome === 'sin_documentacion' ? 'primary' : 'gray'" wire:click="sendToOwner">{{ __('asistente.duda.enviar') }}</x-filament::button></div>
                            @endif
                        </div>
                    @endif

                    @if (in_array($query->outcome, ['respondida', 'sin_documentacion', 'aclaracion'], true))
                        <div class="cl-ask__row">
                            <span class="cl-ask__muted">{{ __('asistente.valoracion.pregunta') }}</span>
                            <x-filament::button size="xs" :color="$query->rating === 1 ? 'success' : 'gray'" icon="heroicon-m-hand-thumb-up" wire:click="rate(1)">{{ __('asistente.valoracion.util') }}</x-filament::button>
                            <x-filament::button size="xs" :color="$query->rating === -1 ? 'danger' : 'gray'" icon="heroicon-m-hand-thumb-down" wire:click="rate(-1)">{{ __('asistente.valoracion.no_util') }}</x-filament::button>
                            <input type="text" class="cl-ask__input" style="flex:1;min-width:12rem" wire:model="comment" maxlength="1000" placeholder="{{ __('asistente.valoracion.comentario') }}" aria-label="{{ __('asistente.valoracion.comentario') }}">
                        </div>
                    @endif
                </section>
            @endif
        </div>

        <aside class="cl-ask__box cl-ask__history" style="align-self:start">
            <strong style="font-size:.9rem">{{ __('asistente.historial') }}</strong>
            <ul style="margin-top:.5rem;display:flex;flex-direction:column;gap:.4rem">
                @forelse ($this->history() as $item)
                    <li style="font-size:.85rem">
                        <button type="button" wire:click="show({{ $item->id }})">{{ \Illuminate\Support\Str::limit($item->question, 80) }}</button>
                        <span class="cl-ask__muted">· {{ __('asistente.resultados.'.$item->outcome) }} · {{ $item->created_at?->timezone('Europe/Madrid')->format('d/m H:i') }}</span>
                    </li>
                @empty
                    <li class="cl-ask__muted">{{ __('asistente.historial_vacio') }}</li>
                @endforelse
            </ul>
        </aside>
    </div>
</x-filament-panels::page>
