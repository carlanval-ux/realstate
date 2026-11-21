<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\SignatureRequestResource\Pages;
use App\Signing\SignatureRequest;
use App\Signing\SignatureSigner;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Liberu\RealEstate\Parties\Models\Party;
use Liberu\RealEstate\Properties\Models\Property;

/** Documents sent for signature: state per request and per signer, signed PDF and evidence (staff only). */
final class SignatureRequestResource extends Resource
{
    protected static ?string $model = SignatureRequest::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-pencil-square';

    protected static ?string $slug = 'firmas';

    protected static ?int $navigationSort = 41;

    public static function canViewAny(): bool
    {
        return FormTemplateResource::staff();
    }

    public static function canView(Model $record): bool
    {
        return FormTemplateResource::staff();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function isScopedToTenant(): bool
    {
        return false;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('filament.nav_groups.instructions_media');
    }

    public static function getNavigationLabel(): string
    {
        return __('Signatures');
    }

    public static function getModelLabel(): string
    {
        return __('signature request');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Signatures');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Document'))->columns(3)->schema([
                TextEntry::make('title')->label(__('Title'))->columnSpan(2),
                TextEntry::make('status')->label(__('Status'))->badge()
                    ->formatStateUsing(fn (string $state): string => __(SignatureRequest::STATUSES[$state][0] ?? $state))
                    ->color(fn (string $state): string => SignatureRequest::STATUSES[$state][1] ?? 'gray'),
                TextEntry::make('template_key')->label(__('Form'))->placeholder(__('Attached PDF'))
                    ->formatStateUsing(fn (SignatureRequest $r): string => $r->template_key.' · v'.$r->template_version),
                TextEntry::make('signature_level')->label(__('Signature level'))->formatStateUsing(fn (string $state): string => __(\App\Signing\FormTemplate::LEVELS[$state] ?? $state)),
                TextEntry::make('driver')->label(__('Provider'))->formatStateUsing(fn (SignatureRequest $r): string => $r->driver === 'simulado' ? __('Simulation (nothing was sent)') : 'DocuSeal · '.$r->environment.' · #'.$r->provider_submission_id),
                TextEntry::make('party_id')->label(__('Owner'))->formatStateUsing(fn ($state): string => Party::query()->find($state)?->name ?? '—')->placeholder('—'),
                TextEntry::make('property_id')->label(__('Property'))->formatStateUsing(fn ($state): string => Property::query()->find($state)?->reference() ?? '—')->placeholder('—'),
                TextEntry::make('instruction_id')->label(__('Mandate'))->formatStateUsing(fn ($state): string => '#'.$state)->placeholder('—'),
                TextEntry::make('sent_at')->label(__('Sent'))->dateTime('d/m/Y H:i', 'Europe/Madrid')->placeholder('—'),
                TextEntry::make('completed_at')->label(__('Signed'))->dateTime('d/m/Y H:i', 'Europe/Madrid')->placeholder('—'),
                TextEntry::make('reconciled_at')->label(__('Last checked with the provider'))->dateTime('d/m/Y H:i', 'Europe/Madrid')->placeholder('—'),
                TextEntry::make('document_sha256')->label(__('SHA-256 of the PDF sent'))->fontFamily('mono')->columnSpanFull(),
                TextEntry::make('last_error')->label(__('Error'))->color('danger')->columnSpanFull()->visible(fn (SignatureRequest $r): bool => filled($r->last_error)),
            ]),
            Section::make(__('Signers'))->schema([
                RepeatableEntry::make('signers')->hiddenLabel()->columns(4)->schema([
                    TextEntry::make('name')->label(__('Name'))->formatStateUsing(fn (SignatureSigner $s): string => $s->name.' · '.$s->role),
                    TextEntry::make('email')->label(__('Contact'))->formatStateUsing(fn (SignatureSigner $s): string => trim($s->email.' '.($s->phone ? '+'.$s->phone : '')))->placeholder('—'),
                    TextEntry::make('status')->label(__('Status'))->badge()->formatStateUsing(fn (string $state): string => __(SignatureSigner::STATUSES[$state] ?? $state))
                        ->color(fn (string $state): string => ['firmado' => 'success', 'visto' => 'warning', 'rechazado' => 'danger'][$state] ?? 'gray'),
                    TextEntry::make('otp_verified_at')->label(__('Code by SMS (DocuSeal)'))->dateTime('d/m/Y H:i', 'Europe/Madrid')
                        ->placeholder(fn (SignatureSigner $s): string => $s->require_phone_2fa ? __('Requested, not confirmed yet') : __('Not requested')),
                ]),
            ]),
            Section::make(__('Data sent'))->collapsed()->schema([
                KeyValueEntry::make('values')->hiddenLabel()->keyLabel(__('Field'))->valueLabel(__('Value')),
            ]),
            Section::make(__('Signed document and evidence'))->schema([
                RepeatableEntry::make('documents')->hiddenLabel()->columns(3)->placeholder(__('Available when everyone has signed.'))->schema([
                    TextEntry::make('kind')->label(__('Type'))->formatStateUsing(fn (string $state): string => __(\App\Signing\SignatureDocument::KINDS[$state] ?? $state)),
                    TextEntry::make('name')->label(__('Name')),
                    TextEntry::make('sha256')->label('SHA-256')->fontFamily('mono'),
                ]),
            ]),
            Section::make(__('SMS'))->collapsed()->schema([
                RepeatableEntry::make('smsMessages')->hiddenLabel()->columns(4)->placeholder(__('No SMS sent.'))->schema([
                    TextEntry::make('created_at')->label(__('Date'))->dateTime('d/m/Y H:i', 'Europe/Madrid'),
                    TextEntry::make('kind')->label(__('Type'))->formatStateUsing(fn (string $state): string => $state === 'enlace' ? __('Signing link') : __('Reminder')),
                    TextEntry::make('to')->label(__('To'))->prefix('+'),
                    TextEntry::make('status')->label(__('Status'))->formatStateUsing(fn ($record): string => $record->status.($record->error ? ' · '.$record->error : '')),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->label(__('Title'))->searchable()
                ->description(fn (SignatureRequest $r): string => $r->template_key ? $r->template_key.' · v'.$r->template_version : __('Attached PDF')),
            TextColumn::make('party_id')->label(__('Owner'))->formatStateUsing(fn ($state): string => Party::query()->find($state)?->name ?? '—'),
            TextColumn::make('property_id')->label(__('Property'))->formatStateUsing(fn ($state): string => Property::query()->find($state)?->reference() ?? '—'),
            TextColumn::make('status')->label(__('Status'))->badge()
                ->formatStateUsing(fn (string $state): string => __(SignatureRequest::STATUSES[$state][0] ?? $state))
                ->color(fn (string $state): string => SignatureRequest::STATUSES[$state][1] ?? 'gray'),
            TextColumn::make('driver')->label(__('Provider'))->formatStateUsing(fn (string $state): string => $state === 'simulado' ? __('simulation') : 'DocuSeal'),
            TextColumn::make('sent_at')->label(__('Sent'))->dateTime('d/m/Y H:i', 'Europe/Madrid')->sortable(),
        ])->defaultSort('id', 'desc')
            ->filters([SelectFilter::make('status')->label(__('Status'))->options(fn () => array_map(fn (array $s): string => __($s[0]), SignatureRequest::STATUSES))])
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSignatureRequests::route('/'),
            'view' => Pages\ViewSignatureRequest::route('/{record}'),
        ];
    }
}
