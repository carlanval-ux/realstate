{{-- A client's thread, every channel, oldest first (App\Communications\Communications::timeline). --}}
@use('App\Models\Communication')
@php
    $sources = ['panel' => null, 'alquileres' => __('Rentals'), 'firma' => __('Signatures'), 'sistema' => __('System')];
    $statusColor = ['error' => '#dc2626', 'leido' => '#2563eb', 'entregado' => '#16a34a', 'enviado' => '#6b7280', 'pendiente' => '#d97706'];
@endphp
<div style="display:flex;flex-direction:column;gap:.75rem">
    <div style="display:flex;flex-wrap:wrap;gap:.25rem 1rem;font-size:.8rem;opacity:.75">
        <strong style="opacity:1">{{ $party->name }}</strong>
        @if ($party->phone)<span>{{ $party->phone }}</span>@endif
        @if ($party->email)<span>{{ $party->email }}</span>@endif
        <span>{{ $party->consent_at ? __('Consents to commercial messages') : __('Service messages only') }}</span>
    </div>

    <div style="display:flex;flex-direction:column;gap:.6rem;max-height:30rem;overflow-y:auto;padding:.5rem;border-radius:.75rem;background:rgba(127,127,127,.06)"
         x-data x-init="$el.scrollTop = $el.scrollHeight">
        @forelse ($items as $m)
            @php
                $out = $m->direction === Communication::OUT;
                [$label, $icon, $color] = Communication::CHANNELS[$m->channel] ?? [$m->channel, 'heroicon-o-chat-bubble-left', '#6b7280'];
                $who = $out ? ($m->author?->name ?? $sources[$m->source] ?? __('Panel')) : $party->name;
            @endphp
            <div style="align-self:{{ $out ? 'flex-end' : 'flex-start' }};max-width:85%;display:flex;gap:.5rem;flex-direction:{{ $out ? 'row-reverse' : 'row' }}">
                <span title="{{ __($label) }}" style="flex:none;display:inline-flex;align-items:center;justify-content:center;width:1.75rem;height:1.75rem;border-radius:999px;color:#fff;background:{{ $color }}">
                    <x-filament::icon :icon="$icon" style="width:1rem;height:1rem" />
                </span>
                <div style="padding:.5rem .75rem;border-radius:.75rem;font-size:.875rem;line-height:1.45;white-space:pre-wrap;word-break:break-word;background:{{ $out ? 'rgba(37,99,235,.10)' : 'rgba(127,127,127,.14)' }};{{ $m->status === 'error' ? 'outline:1px solid #dc2626' : '' }}">@if ($m->subject)<strong>{{ $m->subject }}</strong>
@endif{{ $m->body }}<div style="margin-top:.3rem;font-size:.7rem;opacity:.75;text-align:{{ $out ? 'right' : 'left' }}">{{ ($m->sent_at ?? $m->created_at)?->timezone('Europe/Madrid')->format('d/m/Y H:i') }} · {{ __($label) }} · {{ $out ? '↗' : '↙' }} {{ $who }}@if ($m->address()) · {{ $m->address() }}@endif @if ($out)· <span style="color:{{ $statusColor[$m->status] ?? 'inherit' }};font-weight:600">{{ $m->statusLabel() }}</span>@endif @if ($m->purpose && $m->purpose !== 'servicio')· {{ __(Communication::PURPOSE_LABELS[$m->purpose] ?? $m->purpose) }}@endif @if ($m->status === 'error' && $m->error)<div style="color:#dc2626">{{ $m->error }}</div>@endif</div></div>
            </div>
        @empty
            <p style="font-size:.875rem;opacity:.7;text-align:center;padding:1.5rem 0">{{ __('No communications with this client yet.') }}</p>
        @endforelse
    </div>

</div>
