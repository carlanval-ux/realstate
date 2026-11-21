<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\FormTemplateResource\Pages;
use App\Signing\FormTemplate;
use App\Signing\Signing;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * «Formularios»: catalogue of document templates for signature. A version already sent is read-only (only its
 * status changes); "New version" copies it as a draft with the next number.
 */
final class FormTemplateResource extends Resource
{
    protected static ?string $model = FormTemplate::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $slug = 'formularios';

    protected static ?int $navigationSort = 40;

    /** Data the fields can be filled from (dot paths; "a|b" = first one with a value). */
    public const SOURCES = [
        'propietario.name', 'propietario.email', 'propietario.phone', 'propietario.perfil.tax_id', 'propietario.perfil.fiscal_address',
        'propietario.perfil.fiscal_city', 'propietario.perfil.representative_name', 'propietario.metadata.documento',
        'inmueble.referencia', 'inmueble.address', 'inmueble.postal_code', 'inmueble.municipio', 'inmueble.area_sqft', 'inmueble.bedrooms',
        'inmueble.bathrooms', 'inmueble.price', 'inmueble.operacion', 'encargo.subject', 'hoy',
    ];

    public static function staff(): bool
    {
        $user = auth()->user();

        return $user !== null && method_exists($user, 'isAdmin') && $user->isAdmin();
    }

    public static function canViewAny(): bool
    {
        return self::staff();
    }

    public static function canCreate(): bool
    {
        return self::staff();
    }

    public static function canEdit(Model $record): bool
    {
        return self::staff();
    }

    public static function canDelete(Model $record): bool
    {
        return self::staff() && $record instanceof FormTemplate && ! $record->isLocked();
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
        return __('Forms');
    }

    public static function getModelLabel(): string
    {
        return __('form');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Forms');
    }

    public static function form(Schema $schema): Schema
    {
        $locked = fn (?FormTemplate $record): bool => $record?->isLocked() ?? false;

        return $schema->components([
            Section::make(__('Template'))->columns(3)
                ->description(fn (?FormTemplate $record): ?string => $record?->isLocked()
                    ? __('This version has already been sent for signature: only its status can change. Use "New version" to modify it.') : null)
                ->schema([
                    TextInput::make('name')->label(__('Name'))->required()->maxLength(255)->columnSpan(2)->disabled($locked),
                    Select::make('status')->label(__('Status'))->options(fn () => array_map('__', FormTemplate::STATUSES))->required()->default('borrador'),
                    TextInput::make('key')->label(__('Key'))->required()->alphaDash()->maxLength(80)->disabled($locked)
                        ->helperText(__('Same key for every version of the document.')),
                    TextInput::make('version')->label(__('Version'))->numeric()->minValue(1)->default(1)->required()->disabled($locked),
                    Select::make('signature_level')->label(__('Signature level'))->options(fn () => array_map('__', FormTemplate::LEVELS))->required()->default('simple')->disabled($locked)
                        ->helperText(__('Advanced: only with DocuSeal EU (production) and a template with identity verification.')),
                    Textarea::make('notes')->label(__('Notes'))->rows(2)->columnSpanFull(),
                ]),
            Section::make(__('Source'))->columns(2)->disabled($locked)->schema([
                Select::make('source')->label(__('Source'))->options(fn () => array_map('__', FormTemplate::SOURCES))->required()->default('docuseal')->live(),
                TextInput::make('docuseal_template_ids.sandbox')->label(__('DocuSeal template id (sandbox)'))->numeric()
                    ->visible(fn (Get $get): bool => $get('source') === 'docuseal'),
                TextInput::make('docuseal_template_ids.production')->label(__('DocuSeal template id (production)'))->numeric()
                    ->visible(fn (Get $get): bool => $get('source') === 'docuseal'),
                FileUpload::make('pdf_path')->label(__('PDF'))->disk(fn () => config('firma.disk'))->directory('firma/plantillas')->visibility('private')
                    ->acceptedFileTypes(['application/pdf'])->maxSize(15 * 1024)
                    ->helperText(__('Signature fields as DocuSeal text tags in the PDF, e.g. {{Firma;role=Propietario;type=signature}}.'))
                    ->visible(fn (Get $get): bool => $get('source') === 'pdf')->required(fn (Get $get): bool => $get('source') === 'pdf'),
                Textarea::make('body')->label(__('Preview text'))->rows(8)->columnSpanFull()
                    ->helperText(__('Text of the preview with {{field_key}} where each value goes.')),
            ]),
            Section::make(__('Signer roles'))->disabled($locked)->schema([
                Repeater::make('roles')->hiddenLabel()->columns(4)->defaultItems(1)->minItems(1)->schema([
                    TextInput::make('key')->label(__('Key'))->required()->alphaDash(),
                    TextInput::make('label')->label(__('Label'))->required(),
                    TextInput::make('docuseal_role')->label(__('Role in DocuSeal')),
                    Select::make('from')->label(__('Filled from'))->options(fn () => ['owner' => __('Owner'), 'staff' => __('User sending'), 'other' => __('Typed in')])->default('other'),
                ]),
            ]),
            Section::make(__('Fields'))->disabled($locked)->schema([
                Repeater::make('fields')->hiddenLabel()->columns(6)->defaultItems(0)->schema([
                    TextInput::make('key')->label(__('Key'))->required()->alphaDash(),
                    TextInput::make('label')->label(__('Label'))->required(),
                    TextInput::make('source')->label(__('Data'))->datalist(self::SOURCES)->helperText(__('Empty = typed in when sending')),
                    TextInput::make('docuseal_field')->label(__('Field in DocuSeal')),
                    TextInput::make('role')->label(__('Role')),
                    Toggle::make('required')->label(__('Required'))->inline(false),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label(__('Name'))->searchable()->description(fn (FormTemplate $r): string => $r->key.($r->fictitious ? ' · '.__('fictitious') : '')),
            TextColumn::make('version')->label(__('Version'))->prefix('v')->sortable(),
            TextColumn::make('status')->label(__('Status'))->badge()->formatStateUsing(fn (string $state): string => __(FormTemplate::STATUSES[$state] ?? $state))
                ->color(fn (string $state): string => ['activo' => 'success', 'borrador' => 'gray', 'retirado' => 'danger'][$state] ?? 'gray'),
            TextColumn::make('signature_level')->label(__('Signature level'))->formatStateUsing(fn (string $state): string => __(FormTemplate::LEVELS[$state] ?? $state)),
            TextColumn::make('source')->label(__('Source'))->formatStateUsing(fn (string $state): string => __(FormTemplate::SOURCES[$state] ?? $state)),
            IconColumn::make('used_at')->label(__('Used'))->boolean()->getStateUsing(fn (FormTemplate $r): bool => $r->isLocked()),
        ])->defaultSort('key')
            ->recordActions([
                EditAction::make(),
                Action::make('newVersion')->label(__('New version'))->icon('heroicon-m-document-duplicate')->requiresConfirmation()
                    ->visible(fn (FormTemplate $r): bool => $r->isLocked() && self::staff())
                    ->action(fn (FormTemplate $r) => redirect(self::getUrl('edit', ['record' => $r->newVersion()]))),
                Action::make('preview')->label(__('Preview'))->icon('heroicon-m-eye')->color('gray')
                    ->action(fn (FormTemplate $r) => response()->streamDownload(function () use ($r): void {
                        echo Signing::documentPdf($r, [], $r->name);
                    }, 'vista-previa-'.$r->key.'-v'.$r->version.'.pdf', ['Content-Type' => 'application/pdf'])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFormTemplates::route('/'),
            'create' => Pages\CreateFormTemplate::route('/create'),
            'edit' => Pages\EditFormTemplate::route('/{record}/edit'),
        ];
    }
}
