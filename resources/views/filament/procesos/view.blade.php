@php
    $d = $this->data();
    $p = $d['p']; $v = $d['v']; $state = $d['state'];
    $checked = collect((array) $state->checked_steps);
    $steps = array_values((array) $v->steps);
    $decisions = collect((array) $v->decisions)->keyBy('key');
    $numbers = $v->stepNumbers();
    $anchors = collect($d['sections'])->pluck('anchor');
@endphp
<x-filament-panels::page>
    @include('filament.procesos.styles')
    <div class="clp" x-data="{ modo: false, i: 0, n: {{ count($steps) }}, zoom: null }">
        @if (session('clp-notice'))<div class="clp-notice" role="status">{{ session('clp-notice') }}</div>@endif
        @if (! $d['isCurrent'])
            <div class="clp-notice" role="status">{{ $v->isOpen() ? __('procesos.ver.aviso_borrador') : __('procesos.ver.aviso_historial') }}</div>
        @endif
        @if ($v->review_notes && $v->status === 'borrador')
            <div class="clp-notice"><strong>{{ __('procesos.ver.devuelto') }}</strong> {{ $v->review_notes }}</div>
        @endif

        <div class="clp-meta" style="margin-bottom:1rem">
            <span class="clp-tag">{{ $p->area?->label() }}</span>
            <span class="clp-tag">{{ __('procesos.categorias.'.$p->category) }}</span>
            <span class="clp-tag">{{ __('procesos.lineas.'.$p->business_line) }}</span>
            <span class="clp-tag">{{ __('procesos.formatos.'.$p->kind) }}</span>
            @if ($p->visibility === 'restringido')<span class="clp-tag clp-tag--warn">{{ __('procesos.visibilidades.restringido') }}</span>@endif
            @if ($v->ai_assisted)<span class="clp-tag clp-tag--warn">{{ __('procesos.ver.ia') }}</span>@endif
            <span>{{ __('procesos.campos.owner') }}: <strong>{{ $p->owner?->name ?? '—' }}</strong></span>
            <span>{{ __('procesos.campos.next_review_on') }}: {{ $p->next_review_on?->format('d/m/Y') ?? '—' }}</span>
            @if ($v->published_at)<span>{{ __('procesos.campos.published_at') }}: {{ $v->published_at->format('d/m/Y') }}</span>@endif
            <span style="margin-left:auto;display:flex;gap:.5rem;flex-wrap:wrap">
                <button type="button" class="clp-btn" wire:click="toggleFavorite" aria-pressed="{{ $state->favorite ? 'true' : 'false' }}">{{ $state->favorite ? '★ '.__('procesos.ver.quitar_favorito') : '☆ '.__('procesos.ver.favorito') }}</button>
                @if ($d['assistant'])<a class="clp-btn" href="{{ $d['assistant'] }}">{{ __('procesos.inicio.preguntar') }}</a>@endif
                @if (count($steps) > 0)
                    <button type="button" class="clp-btn clp-btn--primary" @click="modo = !modo; i = 0; $nextTick(() => document.getElementById('clp-pasos')?.scrollIntoView())"
                        x-text="modo ? @js(__('procesos.ver.ver_todo')) : @js(__('procesos.ver.paso_a_paso'))">{{ __('procesos.ver.paso_a_paso') }}</button>
                @endif
            </span>
        </div>

        <div class="clp-doc">
            <nav class="clp-toc" aria-label="{{ __('procesos.ver.indice') }}" x-show="!modo">
                <strong>{{ __('procesos.ver.indice') }}</strong>
                @foreach ($d['sections'] as $s)
                    @if (! str_starts_with($s['anchor'], 'decision-') && ! str_starts_with($s['anchor'], 'contenido-'))
                        <a href="#{{ $s['anchor'] }}" @if (str_starts_with($s['anchor'], 'paso-')) style="padding-left:.6rem" @endif>{{ \Illuminate\Support\Str::limit($s['heading'], 60) }}</a>
                    @endif
                @endforeach
                @if ($decisions->isNotEmpty())<a href="#decisiones">{{ __('procesos.secciones.decisiones') }}</a>@endif
                @if ($d['related']->isNotEmpty())<a href="#relacionados">{{ __('procesos.secciones.relacionados') }}</a>@endif
                <a href="#cambios">{{ __('procesos.secciones.cambios') }}</a>
            </nav>

            <article class="clp-body">
                <div x-show="!modo">
                    <h2 id="resumen">{{ __('procesos.secciones.resumen') }}</h2>
                    @if ($v->summary)<p style="font-size:1.05rem">{{ $v->summary }}</p>@endif
                    <dl class="clp-dl" style="margin-top:.75rem">
                        @if ($v->objective)<dt>{{ __('procesos.campos.objective') }}</dt><dd class="clp-prose">{{ $this->md($v->objective) }}</dd>@endif
                        @if ($v->expected_result)<dt>{{ __('procesos.campos.expected_result') }}</dt><dd class="clp-prose">{{ $this->md($v->expected_result) }}</dd>@endif
                        <dt>{{ __('procesos.campos.positions') }}</dt><dd>{{ $p->positions->map->label()->join(', ') ?: '—' }}</dd>
                    </dl>

                    @if ($anchors->contains('inicio'))
                        <h2 id="inicio">{{ __('procesos.secciones.inicio') }}</h2>
                        <dl class="clp-dl">
                            @foreach (['trigger', 'requirements', 'required_data'] as $f)
                                @if ($v->{$f})<dt>{{ __('procesos.campos.'.$f) }}</dt><dd class="clp-prose">{{ $this->md($v->{$f}) }}</dd>@endif
                            @endforeach
                        </dl>
                    @endif
                </div>

                @if (count($steps) > 0)
                    <h2 id="clp-pasos" style="display:flex;align-items:center;gap:.75rem">{{ __('procesos.secciones.pasos') }}
                        <span class="clp-meta">{{ __('procesos.ver.lista_personal', ['n' => $checked->intersect(array_keys($numbers))->count(), 'total' => count($steps)]) }}</span>
                        @if ($checked->isNotEmpty())<button type="button" class="clp-btn" style="font-size:.75rem;padding:.25rem .5rem" wire:click="resetChecklist">{{ __('procesos.ver.reiniciar') }}</button>@endif
                    </h2>
                    @foreach ($steps as $idx => $step)
                        @php($key = (string) ($step['key'] ?? $idx))
                        <section id="paso-{{ $key }}" class="clp-step {{ $checked->contains($key) ? 'clp-step--checked' : '' }}" x-show="!modo || i === {{ $idx }}" wire:key="step-{{ $key }}">
                            <header>
                                <span class="clp-step__n" aria-hidden="true">{{ $idx + 1 }}</span>
                                <div>
                                    <h3>{{ $step['title'] ?? '' }}</h3>
                                    <div class="clp-meta">
                                        @if ($actor = $v->stepActor($step))<span>👤 {{ $actor }}</span>@endif
                                        @if (filled($step['deadline'] ?? null))<span>⏱ {{ $step['deadline'] }}</span>@endif
                                        @if ($t = $this->tarmio($step['tarmio_kind'] ?? null))<span class="clp-tag">{{ __('procesos.ver.tarmio', ['tipo' => $t]) }}</span>@endif
                                    </div>
                                </div>
                                <label class="clp-step__done">
                                    <input type="checkbox" wire:click="toggleStep('{{ $key }}')" @checked($checked->contains($key))> {{ __('procesos.ver.hecho_paso') }}
                                </label>
                            </header>
                            <div class="clp-prose" style="margin-top:.5rem">{{ $this->md($step['action'] ?? null) }}</div>
                            @if (filled($step['done_when'] ?? null))
                                <div class="clp-check"><strong>{{ __('procesos.campos.done_when') }}:</strong> <span class="clp-prose">{{ $this->md($step['done_when']) }}</span></div>
                            @endif
                            @if (filled($step['evidence'] ?? null))<p class="clp-meta"><strong>{{ __('procesos.campos.evidence') }}:</strong>&nbsp;{{ \App\Processes\Models\ProcessVersion::plain($step['evidence']) }}</p>@endif
                            @include('filament.procesos.blocks', ['blocks' => (array) ($step['blocks'] ?? []), 'teamId' => $p->team_id])
                            @if (($link = $step['work_link'] ?? null) && ($url = \App\Processes\ProcessLinks::resolve($link['url'] ?? null, $p->team_id)))
                                <p style="margin-top:.5rem"><a class="clp-btn" href="{{ $url }}">↗ {{ $link['label'] ?? __('procesos.ver.ir_trabajo') }}</a></p>
                            @endif
                            @if (($dk = $step['decision_key'] ?? null) && ($dec = $decisions->get($dk)))
                                <div class="clp-decision">
                                    <strong>◆ {{ $dec['question'] ?? '' }}</strong>
                                    <ul>
                                        @foreach ((array) ($dec['options'] ?? []) as $o)
                                            @php($goto = (string) ($o['goto'] ?? ''))
                                            <li>
                                                <strong>{{ $o['label'] ?? '' }}</strong> →
                                                @if (str_starts_with($goto, 'step:') && isset($numbers[substr($goto, 5)]))
                                                    <a href="#paso-{{ substr($goto, 5) }}" @click="if (modo) { i = {{ $numbers[substr($goto, 5)] - 1 }} }" class="clp-prose" style="text-decoration:underline">{{ $v->gotoLabel($goto) }}</a>
                                                @else
                                                    {{ $v->gotoLabel($goto) }}
                                                @endif
                                                @if (filled($o['note'] ?? null))<span class="clp-meta">· {{ \App\Processes\Models\ProcessVersion::plain($o['note']) }}</span>@endif
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        </section>
                    @endforeach
                    <div class="clp-stepper" x-show="modo" x-cloak>
                        <button type="button" class="clp-btn" @click="i = Math.max(0, i - 1)" :disabled="i === 0">← {{ __('procesos.ver.anterior') }}</button>
                        <span class="clp-meta" style="align-self:center" x-text="(i + 1) + ' / ' + n"></span>
                        <button type="button" class="clp-btn clp-btn--primary" @click="i < n - 1 ? i++ : (modo = false)" x-text="i < n - 1 ? @js(__('procesos.ver.siguiente')).concat(' →') : @js(__('procesos.ver.terminar'))"></button>
                    </div>
                @endif

                <div x-show="!modo">
                    @if ($decisions->isNotEmpty())
                        <h2 id="decisiones">{{ __('procesos.secciones.decisiones') }}</h2>
                        @foreach ($decisions as $dec)
                            <div class="clp-decision" id="decision-{{ $dec['key'] ?? '' }}">
                                <strong>◆ {{ $dec['question'] ?? '' }}</strong>
                                @if ($who = $v->positionName(isset($dec['decider_position_id']) ? (int) $dec['decider_position_id'] : null))<span class="clp-meta"> · {{ __('procesos.campos.decider') }}: {{ $who }}</span>@endif
                                <ul>
                                    @foreach ((array) ($dec['options'] ?? []) as $o)
                                        @php($goto = (string) ($o['goto'] ?? ''))
                                        <li><strong>{{ __('procesos.ver.si') }} {{ $o['label'] ?? '' }}</strong> →
                                            @if (str_starts_with($goto, 'step:'))<a href="#paso-{{ substr($goto, 5) }}" style="text-decoration:underline">{{ $v->gotoLabel($goto) }}</a>@else{{ $v->gotoLabel($goto) }}@endif
                                            @if (filled($o['note'] ?? null))<span class="clp-meta">· {{ \App\Processes\Models\ProcessVersion::plain($o['note']) }}</span>@endif
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    @endif

                    @if ($anchors->contains('excepciones'))
                        <h2 id="excepciones">{{ __('procesos.secciones.excepciones') }}</h2>
                        @foreach ((array) $v->exceptions as $e)
                            <div class="clp-callout clp-callout--aviso"><strong>{{ $e['case'] ?? '' }}</strong><div class="clp-prose">{{ $this->md($e['handling'] ?? null) }}</div>
                                @if (filled($e['escalate_to'] ?? null))<p class="clp-meta">{{ __('procesos.campos.escalate_to') }}: {{ $e['escalate_to'] }}</p>@endif</div>
                        @endforeach
                        @if ($v->escalation)<dl class="clp-dl"><dt>{{ __('procesos.campos.escalation') }}</dt><dd class="clp-prose">{{ $this->md($v->escalation) }}</dd></dl>@endif
                    @endif

                    @if ($anchors->contains('herramientas'))
                        <h2 id="herramientas">{{ __('procesos.secciones.herramientas') }}</h2>
                        <ul class="clp-prose">
                            @foreach ((array) $v->tools as $t)
                                @php($url = filled($t['attachment_id'] ?? null) ? $this->attachmentUrl((int) $t['attachment_id']) : \App\Processes\ProcessLinks::resolve($t['url'] ?? null, $p->team_id))
                                <li>@if ($url)<a href="{{ $url }}" @if (! filled($t['attachment_id'] ?? null) && str_starts_with($url, 'https://') && ! str_starts_with($url, url('/'))) target="_blank" rel="noopener noreferrer" @endif>{{ $t['name'] ?? '' }}</a>@else{{ $t['name'] ?? '' }}@endif
                                    <span class="clp-meta">({{ __('procesos.herramienta_tipos.'.($t['type'] ?? 'liberu')) }}){{ filled($t['note'] ?? null) ? ' · '.$t['note'] : '' }}</span></li>
                            @endforeach
                        </ul>
                        @if ($v->evidences)<dl class="clp-dl"><dt>{{ __('procesos.campos.evidences') }}</dt><dd class="clp-prose">{{ $this->md($v->evidences) }}</dd></dl>@endif
                    @endif

                    @if ($anchors->contains('plazos'))
                        <h2 id="plazos">{{ __('procesos.secciones.plazos') }}</h2>
                        @if ($v->deadlines)<div class="clp-prose">{{ $this->md($v->deadlines) }}</div>@endif
                        @if (count((array) $v->kpis))
                            <table class="clp-table" style="margin-top:.5rem"><thead><tr><th>{{ __('procesos.campos.kpis') }}</th><th>{{ __('procesos.campos.target') }}</th><th>{{ __('procesos.campos.frequency') }}</th><th>{{ __('procesos.campos.source') }}</th></tr></thead>
                                <tbody>@foreach ((array) $v->kpis as $k)<tr><td>{{ $k['name'] ?? '' }}</td><td>{{ $k['target'] ?? '' }}</td><td>{{ $k['frequency'] ?? '' }}</td><td>{{ $k['source'] ?? '' }}</td></tr>@endforeach</tbody></table>
                        @endif
                    @endif

                    @if ($anchors->contains('diagrama'))
                        <h2 id="diagrama">{{ __('procesos.secciones.diagrama') }}</h2>
                        <details open class="clp-diagram">
                            <summary class="clp-meta">{{ __('procesos.ver.diagrama_ayuda') }}</summary>
                            @if ($d['diagram'])
                                <div class="clp-mm-wrap" role="img" aria-label="{{ __('procesos.secciones.diagrama') }}">{!! $d['diagram'] !!}</div>
                            @else
                                <pre style="white-space:pre-wrap;font-size:.8rem">{{ $v->diagram_mermaid }}</pre>
                            @endif
                        </details>
                    @endif

                    @if (count((array) $v->blocks))
                        <h2 id="contenido">{{ __('procesos.secciones.contenido') }}</h2>
                        @include('filament.procesos.blocks', ['blocks' => (array) $v->blocks, 'teamId' => $p->team_id, 'anchorPrefix' => 'contenido-'])
                    @endif

                    @if ($d['related']->isNotEmpty())
                        <h2 id="relacionados">{{ __('procesos.secciones.relacionados') }}</h2>
                        <ul class="clp-prose">@foreach ($d['related'] as $r)<li>{{ __('procesos.relaciones.'.$r['relation']) }}: <a href="{{ $r['url'] }}">{{ $r['code'] }} · {{ $r['title'] }}</a></li>@endforeach</ul>
                    @endif

                    <h2 id="cambios">{{ __('procesos.secciones.cambios') }}</h2>
                    @if ($v->status === 'publicada' && $d['isCurrent'])
                        <p>
                            @if ((string) $state->read_confirmed_version_id === (string) $v->id)
                                <span class="clp-tag clp-tag--ok">✔ {{ __('procesos.ver.leido', ['n' => $v->number, 'fecha' => $state->read_confirmed_at?->format('d/m/Y')]) }}</span>
                            @else
                                <button type="button" class="clp-btn" wire:click="confirmRead">{{ __('procesos.ver.confirmar_lectura', ['n' => $v->number]) }}</button>
                            @endif
                            @if ($d['readers'] !== null)<span class="clp-meta"> · {{ trans_choice('procesos.ver.lectores', $d['readers'], ['n' => $d['readers']]) }}</span>@endif
                        </p>
                    @endif
                    <table class="clp-table">
                        <thead><tr><th>{{ __('procesos.campos.version') }}</th><th>{{ __('procesos.campos.published_at') }}</th><th>{{ __('procesos.campos.change_summary') }}</th></tr></thead>
                        <tbody>
                            @foreach ($d['history'] as $h)
                                <tr><td>@if ($d['manages'])<a href="{{ \App\Processes\ProcessLinks::version($h) }}" style="text-decoration:underline">v{{ $h->number }}</a>@else v{{ $h->number }} @endif
                                    <span class="clp-meta">{{ __('procesos.estados_version.'.$h->status) }}</span></td>
                                    <td>{{ $h->published_at?->format('d/m/Y') }}</td><td>{{ $h->change_summary }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                    <p class="clp-meta" style="margin-top:.5rem">{{ __('procesos.campos.author') }}: {{ $v->author?->name ?? '—' }} · {{ __('procesos.campos.approved_by') }}: {{ $v->approvedBy?->name ?? '—' }}{{ $v->self_approved ? ' ('.__('procesos.ver.autoaprobado').')' : '' }}</p>
                </div>
            </article>
        </div>

        <template x-if="zoom"><div class="clp-zoom" @click="zoom = null" @keydown.escape.window="zoom = null" role="dialog" aria-label="{{ __('procesos.ver.ampliar') }}"><img :src="zoom" alt=""></div></template>
    </div>
</x-filament-panels::page>
