@use('App\Models\Communication')
<x-filament-panels::page>
    <style>
        .cl-inbox { display: grid; gap: 1rem; grid-template-columns: minmax(0, 1fr); }
        @media (min-width: 1024px) { .cl-inbox { grid-template-columns: 20rem minmax(0, 1fr); } }
        .cl-inbox__list { display: flex; flex-direction: column; gap: .25rem; max-height: 75vh; overflow-y: auto; }
        .cl-inbox__item { display: flex; gap: .6rem; align-items: flex-start; width: 100%; text-align: left; padding: .6rem .7rem; border-radius: .6rem; }
        .cl-inbox__item:hover { background: rgba(127,127,127,.08); }
        .cl-inbox__item--on { background: rgba(37,99,235,.10); }
        .cl-inbox__search { width: 100%; padding: .45rem .7rem; border-radius: .5rem; border: 1px solid rgba(127,127,127,.35); background: transparent; font-size: .875rem; }
    </style>
    <div class="cl-inbox" wire:poll.20s>
        <div style="display:flex;flex-direction:column;gap:.5rem">
            <input type="search" class="cl-inbox__search" wire:model.live.debounce.400ms="search" placeholder="{{ __('Search client, phone or e-mail') }}" aria-label="{{ __('Search client, phone or e-mail') }}">
            @if (($pending = $this->unlinked()) > 0)
                <a href="{{ \App\Filament\Pages\WhatsAppQueue::getUrl() }}" style="font-size:.8rem;color:#d97706;font-weight:600">
                    {{ trans_choice(':count number to link|:count numbers to link', $pending, ['count' => $pending]) }} →
                </a>
            @endif
            <nav class="cl-inbox__list" aria-label="{{ __('Conversations') }}">
                @forelse ($this->threads() as $t)
                    @php([$label, $icon, $color] = Communication::CHANNELS[$t->channel] ?? [$t->channel, 'heroicon-o-chat-bubble-left', '#6b7280'])
                    <button type="button" wire:click="open({{ $t->party_id }})" @class(['cl-inbox__item', 'cl-inbox__item--on' => $cliente === (int) $t->party_id])>
                        <span title="{{ __($label) }}" style="flex:none;display:inline-flex;align-items:center;justify-content:center;width:1.6rem;height:1.6rem;border-radius:999px;color:#fff;background:{{ $color }}">
                            <x-filament::icon :icon="$icon" style="width:.9rem;height:.9rem" />
                        </span>
                        <span style="flex:1;min-width:0">
                            <span style="display:flex;justify-content:space-between;gap:.5rem;font-size:.875rem">
                                <strong style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $t->party_name }}</strong>
                                <span style="flex:none;font-size:.7rem;opacity:.65">{{ ($t->sent_at ?? $t->created_at)?->timezone('Europe/Madrid')->format('d/m H:i') }}</span>
                            </span>
                            <span style="display:flex;justify-content:space-between;gap:.5rem;font-size:.8rem;opacity:.75">
                                <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $t->direction === Communication::OUT ? '↗ ' : '' }}{{ $t->subject ?: $t->body }}</span>
                                @if ((int) $t->unread > 0)
                                    <span style="flex:none;min-width:1.2rem;padding:0 .35rem;border-radius:999px;background:#2563eb;color:#fff;font-size:.7rem;text-align:center">{{ $t->unread }}</span>
                                @endif
                            </span>
                        </span>
                    </button>
                @empty
                    <p style="font-size:.875rem;opacity:.7;padding:1rem .25rem">{{ __('No conversations yet. Send a message from a client record.') }}</p>
                @endforelse
            </nav>
        </div>
        <section>
            @if ($cliente)
                @livewire(\App\Filament\Communications\ClientTimeline::class, ['partyId' => $cliente], key('bandeja-'.$cliente))
            @else
                <p style="font-size:.875rem;opacity:.7">{{ __('Choose a conversation.') }}</p>
            @endif
        </section>
    </div>
</x-filament-panels::page>
