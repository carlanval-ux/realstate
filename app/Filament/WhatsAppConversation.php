<?php

declare(strict_types=1);

namespace App\Filament;

use App\Models\WhatsAppLine;
use App\WhatsApp\Phone;
use App\WhatsApp\WhatsApp;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Liberu\RealEstate\Instructions\Models\Instruction;
use Liberu\RealEstate\Parties\Models\Party;
use RuntimeException;

/**
 * «Send WhatsApp» and the conversation, on a contact (owner 360 profile, contact form) or a file (Instruction).
 * The user reviews recipient and text before sending; templates are filled from the record.
 *
 *   header action:            WhatsAppConversation::action()
 *   owner profile tab:        WhatsAppConversation::tab()      (record = Party)
 */
final class WhatsAppConversation
{
    public static function action(): Action
    {
        return Action::make('whatsapp')
            ->label('WhatsApp')->icon('heroicon-o-chat-bubble-left-right')->color('success')
            ->visible(fn (?Model $record): bool => self::party($record) !== null)
            ->slideOver()
            ->modalHeading(fn (?Model $record): string => __('WhatsApp with :name', ['name' => self::party($record)?->name]))
            ->modalDescription(fn (?Model $record): string => WhatsApp::origin(self::party($record)).' · '.__('Company line: :status', ['status' => WhatsAppLine::forTeam(self::party($record)->team_id)->statusLabel()]))
            ->modalContent(fn (?Model $record) => view('filament.whatsapp.conversation', ['messages' => WhatsApp::conversation(self::party($record))]))
            ->schema(fn (?Model $record): array => self::sendForm($record))
            ->modalSubmitActionLabel(__('Send WhatsApp'))
            ->action(function (array $data, ?Model $record, Action $action): void {
                try {
                    $message = WhatsApp::send(self::party($record), (string) $data['phone'], (string) $data['body'], (string) $data['idempotency_key'],
                        auth()->id(), $record instanceof Instruction ? $record : null);
                } catch (RuntimeException $e) {
                    Notification::make()->title(__('The WhatsApp was not sent'))->body($e->getMessage())->danger()->persistent()->send();
                    $action->halt();

                    return;
                }
                Notification::make()->title(__('WhatsApp sent to :phone', ['phone' => $message->phone]))->success()->send();
            });
    }

    /** «WhatsApp» tab for the owner 360 profile (App\Owners\OwnerProfileSections): history and the send action. */
    public static function tab(): Tab
    {
        return Tab::make('WhatsApp')->icon('heroicon-o-chat-bubble-left-right')->schema([self::section()]);
    }

    public static function section(): Section
    {
        return Section::make('WhatsApp')
            ->description(fn (?Model $record): ?string => self::party($record) ? WhatsApp::origin(self::party($record)) : null)
            ->headerActions([self::action()])
            ->schema([
                View::make('filament.whatsapp.conversation')
                    ->visible(fn (?Model $record): bool => self::party($record) !== null),
            ]);
    }

    public static function party(?Model $record): ?Party
    {
        return match (true) {
            $record instanceof Party => $record,
            $record instanceof Instruction && $record->party_id !== null => Party::query()->where('team_id', $record->team_id)->find($record->party_id),
            default => null,
        };
    }

    /** @return array<int, \Filament\Schemas\Components\Component> */
    private static function sendForm(?Model $record): array
    {
        $party = self::party($record);
        $instruction = $record instanceof Instruction ? $record : null;
        $agent = auth()->user()?->name;

        return [
            TextInput::make('phone')->label(__('Recipient'))->required()->tel()
                ->default(Phone::e164($party?->phone) ?? $party?->phone)
                ->helperText(__('Check the number: the message goes to this WhatsApp.'))
                ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail): void {
                    if (Phone::e164((string) $value) === null) {
                        $fail(__('The recipient is not a valid phone number.'));
                    }
                }),
            Select::make('template')->label(__('Reusable text'))->placeholder(__('Free text'))
                ->options(fn (): array => $party ? array_combine(array_keys($o = WhatsAppLine::forTeam($party->team_id)->templateOptions()), array_keys($o)) : [])
                ->live()->dehydrated(false)
                ->afterStateUpdated(function (?string $state, Set $set) use ($party, $instruction, $agent): void {
                    $body = $state && $party ? (WhatsAppLine::forTeam($party->team_id)->templateOptions()[$state] ?? null) : null;
                    if ($body !== null) {
                        $set('body', WhatsApp::render($body, $party, $instruction, $agent));
                    }
                }),
            Textarea::make('body')->label(__('Message'))->required()->rows(7)->maxLength(4000)
                ->helperText(__('Review the text before sending. Fields left between braces, like {enlace_firma}, must be completed.')),
            Hidden::make('idempotency_key')->default(fn (): string => (string) Str::uuid()),
        ];
    }
}
