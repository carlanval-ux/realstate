{{-- WhatsApp conversation with a contact (App\Filament\WhatsAppConversation). In a schema, the record is the contact or the file. --}}
@php
    $messages ??= isset($getRecord) && ($party = \App\Filament\WhatsAppConversation::party($getRecord()))
        ? \App\WhatsApp\WhatsApp::conversation($party)
        : collect();
@endphp
<div style="display:flex;flex-direction:column;gap:.5rem;max-height:28rem;overflow-y:auto;padding:.25rem 0">
    @forelse ($messages as $m)
        @php($out = $m->direction === \App\Models\WhatsAppMessage::OUT)
        <div style="align-self:{{ $out ? 'flex-end' : 'flex-start' }};max-width:85%;padding:.5rem .75rem;border-radius:.75rem;font-size:.875rem;line-height:1.4;white-space:pre-wrap;word-break:break-word;background:{{ $out ? 'rgba(37,211,102,.14)' : 'rgba(127,127,127,.12)' }}">{{ $m->body }}<div style="margin-top:.25rem;font-size:.7rem;opacity:.65;text-align:{{ $out ? 'right' : 'left' }}">{{ ($m->sent_at ?? $m->created_at)?->timezone('Europe/Madrid')->format('d/m/Y H:i') }} · {{ $out ? $m->statusLabel() : __('Received') }}@if ($m->phone) · {{ $m->phone }}@endif @if ($m->status === 'error' && $m->error) · {{ $m->error }}@endif</div></div>
    @empty
        <p style="font-size:.875rem;opacity:.7">{{ __('No WhatsApp messages with this contact yet.') }}</p>
    @endforelse
</div>
