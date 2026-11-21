<?php

declare(strict_types=1);

namespace App\Filament\Communications;

use App\Communications\Communications;
use App\Models\Communication;
use App\Models\WhatsAppLine;
use App\WhatsApp\Phone;
use App\WhatsApp\WhatsApp;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Liberu\RealEstate\Instructions\Models\Instruction;
use Liberu\RealEstate\Parties\Models\Party;
use RuntimeException;

/**
 * Communications on every client record (owner 360, contacts — tenants, occupants, travellers —, files):
 * «Contact» menu (Send WhatsApp / SMS / e-mail), the «Communications» timeline with its composer, and the same
 * form everywhere. Record = Party, or Instruction (its owner).
 */
final class CommunicationsUi
{
    private const SEND_LABELS = ['whatsapp' => 'Send WhatsApp', 'sms' => 'Send SMS', 'email' => 'Send e-mail'];

    /** Header actions of a client record: «Contact» (three channels) and «Communications» (timeline in a panel). */
    public static function actions(): array
    {
        return [
            ActionGroup::make([self::sendAction('whatsapp'), self::sendAction('sms'), self::sendAction('email')])
                ->label(__('Contact client'))->icon('heroicon-m-paper-airplane')->button()->color('success'),   // hidden when its actions are
            Action::make('communications')->label(__('Communications'))->icon('heroicon-o-inbox-stack')->color('gray')
                ->visible(fn (?Model $record): bool => self::party($record) !== null)
                ->badge(fn (?Model $record): ?int => ($n = self::unread($record)) > 0 ? $n : null)
                ->slideOver()->modalWidth('3xl')->modalHeading(fn (?Model $record): string => __('Communications with :name', ['name' => self::party($record)?->name]))
                ->modalSubmitAction(false)->modalCancelActionLabel(__('Close'))
                ->modalContent(fn (?Model $record) => view('filament.communications.embed', ['partyId' => self::party($record)?->getKey()])),
        ];
    }

    public static function sendAction(string $channel): Action
    {
        return Action::make('send_'.$channel)->label(__(self::SEND_LABELS[$channel]))->icon(Communication::CHANNELS[$channel][1])
            ->visible(fn (?Model $record): bool => self::party($record) !== null)
            ->modalHeading(fn (?Model $record): string => __(self::SEND_LABELS[$channel]).' · '.self::party($record)?->name)
            ->modalDescription(fn (?Model $record): ?string => self::notice($channel, self::party($record)))
            ->schema(fn (?Model $record): array => self::fields(self::party($record), $channel, $record instanceof Instruction ? $record : null))
            ->modalSubmitActionLabel(__('Send'))
            ->action(function (array $data, ?Model $record, Action $action) use ($channel): void {
                if (! self::deliver(self::party($record), $data, $channel, $record instanceof Instruction ? $record : null)) {
                    $action->halt();
                }
            });
    }

    /** «Communications» tab (owner 360 page, OwnerProfileSections). */
    public static function tab(): Tab
    {
        return Tab::make(__('Communications'))->icon('heroicon-o-inbox-stack')
            ->badge(fn (?Model $record): ?int => ($n = self::unread($record)) > 0 ? $n : null)
            ->schema([self::timeline()]);
    }

    /** «Communications» section (below a contact's form). A page's content schema has no record: pass it. */
    public static function section(?Model $for = null): Section
    {
        return Section::make(__('Communications'))->icon('heroicon-o-inbox-stack')->collapsible()
            ->visible(fn (?Model $record): bool => self::party($for ?? $record) !== null)
            ->schema([self::timeline($for)]);
    }

    private static function timeline(?Model $for = null): Livewire
    {
        return Livewire::make(ClientTimeline::class, fn (?Model $record): array => ['partyId' => (int) self::party($for ?? $record)?->getKey()])
            ->key(fn (?Model $record): string => 'comunicaciones-'.self::party($for ?? $record)?->getKey())
            ->visible(fn (?Model $record): bool => self::party($for ?? $record) !== null);
    }

    /**
     * The send form: channel (unless fixed), recipient, subject (e-mail), reusable text, purpose, message.
     *
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    public static function fields(?Party $party, ?string $channel = null, ?Instruction $instruction = null): array
    {
        $current = fn (Get $get): string => $channel ?? ($get('channel') ?: 'whatsapp');
        $agent = auth()->user()?->name;

        return [
            ToggleButtons::make('channel')->hiddenLabel()->inline()->live()->default('whatsapp')->visible($channel === null)
                ->options(collect(Communication::CHANNELS)->map(fn (array $c): string => __($c[0]))->all())
                ->icons(collect(Communication::CHANNELS)->map(fn (array $c): string => $c[1])->all())
                ->afterStateUpdated(fn (?string $state, Set $set) => $set('to', self::defaultTo($party, $state ?? 'whatsapp'))),
            TextInput::make('to')->label(fn (Get $get): string => $current($get) === 'email' ? __('E-mail') : __('Recipient'))->required()
                ->default(self::defaultTo($party, $channel ?? 'whatsapp'))
                ->helperText(__('Check the recipient before sending.')),
            TextInput::make('subject')->label(__('Subject'))->maxLength(200)
                ->visible(fn (Get $get): bool => $current($get) === 'email')->required(fn (Get $get): bool => $current($get) === 'email'),
            Select::make('template')->label(__('Reusable text'))->placeholder(__('Free text'))->live()->dehydrated(false)
                ->options(fn (): array => $party ? array_combine(array_keys($o = WhatsAppLine::forTeam($party->team_id)->templateOptions()), array_keys($o)) : [])
                ->afterStateUpdated(function (?string $state, Set $set) use ($party, $instruction, $agent): void {
                    $body = $state && $party ? (WhatsAppLine::forTeam($party->team_id)->templateOptions()[$state] ?? null) : null;
                    if ($body !== null) {
                        $set('body', WhatsApp::render($body, $party, $instruction, $agent));
                    }
                }),
            Select::make('purpose')->label(__('Purpose'))->required()->default('servicio')->selectablePlaceholder(false)
                ->options(collect(Communication::PURPOSES)->mapWithKeys(fn (string $p): array => [$p => __(Communication::PURPOSE_LABELS[$p])])->all())
                ->disableOptionWhen(fn (string $value): bool => $value === 'marketing' && blank($party?->consent_at))
                ->helperText(blank($party?->consent_at) ? __('No consent for commercial messages: service messages only.') : null),
            Textarea::make('body')->label(__('Message'))->required()->rows(4)
                ->maxLength(fn (Get $get): int => $current($get) === 'sms' ? 160 : 4000)
                ->helperText(fn (Get $get): string => $current($get) === 'sms'
                    ? __('SMS: at most 160 characters. Fields left between braces must be completed.')
                    : __('Review the text before sending. Fields left between braces, like {enlace_firma}, must be completed.')),
            Hidden::make('key')->default(fn (): string => (string) Str::uuid()),
        ];
    }

    /** Sends and tells the user how it went. Returns false when nothing was sent (the form stays open). */
    public static function deliver(?Party $party, array $data, ?string $channel = null, ?Instruction $instruction = null): bool
    {
        $channel ??= $data['channel'] ?? 'whatsapp';
        if ($party === null) {
            return false;
        }
        try {
            $c = Communications::send($party?->team_id, $party, $channel, (string) $data['to'], (string) $data['body'], [
                'key' => $data['key'] ?? null, 'subject' => $data['subject'] ?? null, 'purpose' => $data['purpose'] ?? 'servicio',
                'user_id' => auth()->id(), 'instruction_id' => $instruction?->getKey(), 'related' => $instruction,
            ]);
        } catch (RuntimeException $e) {
            Notification::make()->title(__('Not sent'))->body($e->getMessage())->danger()->send();

            return false;
        }
        if ($c->status === 'error') {
            Notification::make()->title(__(':channel not sent', ['channel' => $c->channelLabel()]))->body($c->error)->danger()->persistent()->send();

            return false;
        }
        Notification::make()->title(__(':channel sent to :to', ['channel' => $c->channelLabel(), 'to' => $c->address()]))->success()->send();

        return true;
    }

    public static function party(?Model $record): ?Party
    {
        return match (true) {
            $record instanceof Party => $record,
            $record instanceof Instruction && $record->party_id !== null => Party::query()->where('team_id', $record->team_id)->find($record->party_id),
            default => null,
        };
    }

    public static function defaultTo(?Party $party, string $channel): ?string
    {
        return $channel === 'email' ? $party?->email : (Phone::e164($party?->phone) ?? $party?->phone);
    }

    private static function unread(?Model $record): int
    {
        $party = self::party($record);

        return $party ? Communication::query()->where('team_id', $party->team_id)->where('party_id', $party->getKey())
            ->where('direction', Communication::IN)->whereNull('read_at')->count() : 0;
    }

    private static function notice(string $channel, ?Party $party): ?string
    {
        return match ($channel) {
            'whatsapp' => $party ? WhatsApp::origin($party).' · '.__('Company line: :status', ['status' => WhatsAppLine::forTeam($party->team_id)->statusLabel()]) : null,
            'sms' => config('firma.sms.driver') === 'redtelecom' ? __('SMS through the provider (only to test numbers until enabled).') : __('SMS simulated: nothing reaches the phone until the SMS provider is enabled.'),
            'email' => __('Sent from the panel mailbox (Platform settings → Outgoing mail).'),
            default => null,
        };
    }
}
