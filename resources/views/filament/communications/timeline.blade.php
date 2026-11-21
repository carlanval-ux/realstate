{{-- A client's communications (App\Filament\Communications\ClientTimeline): thread + composer. --}}
<div wire:poll.15s style="display:flex;flex-direction:column;gap:.75rem">
    @include('filament.communications.thread')

    <form wire:submit="send" style="display:flex;flex-direction:column;gap:.75rem">
        {{ $this->form }}
        <div style="display:flex;justify-content:flex-end">
            <x-filament::button type="submit" icon="heroicon-m-paper-airplane" wire:loading.attr="disabled" wire:target="send">{{ __('Send') }}</x-filament::button>
        </div>
    </form>
    <x-filament-actions::modals />
</div>
