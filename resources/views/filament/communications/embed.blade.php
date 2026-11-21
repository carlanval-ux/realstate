@if ($partyId)
    @livewire(\App\Filament\Communications\ClientTimeline::class, ['partyId' => $partyId], key('comunicaciones-modal-'.$partyId))
@endif
