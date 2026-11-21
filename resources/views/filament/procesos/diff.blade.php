{{-- Comparison modal: $diff = [base version|null, changes], $gaps, $warnings. --}}
@include('filament.procesos.styles')
@php([$base, $changes] = $diff)
<div class="clp">
    @if ($gaps)
        <div class="clp-notice"><strong>{{ __('procesos.ver.faltan') }}</strong><ul class="clp-prose">@foreach ($gaps as $g)<li>{{ $g }}</li>@endforeach</ul></div>
    @endif
    @foreach ($warnings as $w)<p class="clp-meta">⚠ {{ $w }}</p>@endforeach
    @if ($base === null)
        <p><strong>{{ __('procesos.ver.primera_version') }}</strong></p>
    @else
        <p class="clp-meta">{{ __('procesos.ver.comparada_con', ['n' => $base->number ?? '—']) }}</p>
        @if ($changes === [])
            <p class="clp-empty">{{ __('procesos.ver.sin_cambios') }}</p>
        @else
            <table class="clp-table">
                <thead><tr><th>{{ __('procesos.ver.apartado') }}</th><th>{{ __('procesos.ver.cambio') }}</th><th>{{ __('procesos.ver.antes') }}</th><th>{{ __('procesos.ver.despues') }}</th></tr></thead>
                <tbody>
                    @foreach ($changes as $c)
                        <tr>
                            <td>{{ $c['label'] }}</td>
                            <td><span class="clp-tag">{{ __('procesos.cambios.'.$c['change']) }}</span></td>
                            <td class="clp-diff-del">@foreach ($c['removed'] as $l)<div>{{ \Illuminate\Support\Str::limit($l, 400) }}</div>@endforeach</td>
                            <td class="clp-diff-add">@foreach ($c['added'] as $l)<div>{{ \Illuminate\Support\Str::limit($l, 400) }}</div>@endforeach</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endif
</div>
