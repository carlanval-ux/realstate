<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Documents\CompanyDocument;
use App\Documents\CompanyDocumentLog;
use App\Filament\Resources\CompanyDocumentResource\Pages\ManageCompanyDocuments;
use App\Filament\Resources\CompanyDocumentResource\Pages\ViewCompanyDocument;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * «Documentación de la empresa» (menu Organización, /admin only): the company's official documents for the management
 * team. Registered from ClartoCompanyDocumentsServiceProvider; never in global search, the app panel or the API.
 */
final class CompanyDocumentResource extends Resource
{
    protected static ?string $model = CompanyDocument::class;

    protected static ?string $slug = 'documentacion-empresa';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static ?int $navigationSort = 90;

    protected static ?string $recordTitleAttribute = 'title';

    protected static bool $isGloballySearchable = false;

    public static function getNavigationGroup(): string
    {
        return __('filament.nav_groups.organisation');
    }

    public static function getNavigationLabel(): string
    {
        return __('documentacion.menu');
    }

    public static function getModelLabel(): string
    {
        return __('documentacion.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('documentacion.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('title')->label(__('documentacion.fields.title'))->required()->maxLength(200)->columnSpanFull(),
            Select::make('category')->label(__('documentacion.fields.category'))->options(CompanyDocument::categoryOptions())->required()->native(false),
            TextInput::make('subject')->label(__('documentacion.fields.subject'))->maxLength(200)->helperText(__('documentacion.subject_help')),
            DatePicker::make('document_date')->label(__('documentacion.fields.document_date'))->required()->displayFormat('d/m/Y'),
            DatePicker::make('expires_at')->label(__('documentacion.fields.expires_at'))->displayFormat('d/m/Y')->helperText(__('documentacion.expires_help')),
            Textarea::make('notes')->label(__('documentacion.fields.notes'))->maxLength(5000)->rows(4)->columnSpanFull(),
            FileUpload::make('new_files')->label(__('documentacion.fields.new_files'))
                ->helperText(__('documentacion.upload_help', ['max' => CompanyDocument::MAX_FILES, 'mb' => CompanyDocument::MAX_BYTES / 1024 / 1024]))
                ->required(fn (?CompanyDocument $record): bool => blank($record?->files))
                ->multiple()->appendFiles()
                ->acceptedFileTypes(CompanyDocument::MIME_TYPES)
                ->maxSize(CompanyDocument::MAX_BYTES / 1024)
                ->maxFiles(CompanyDocument::MAX_FILES)
                ->disk(CompanyDocument::DISK)
                ->directory(fn (): string => CompanyDocument::uploadDirectory((int) auth()->id()))
                ->visibility('private')
                ->storeFileNamesIn('new_file_names')
                ->columnSpanFull(),
            CheckboxList::make('remove_files')->label(__('documentacion.fields.remove_files'))
                ->options(fn (?CompanyDocument $record): array => array_map(fn (array $file): string => $file['name'], (array) $record?->files))
                ->visible(fn (?CompanyDocument $record): bool => filled($record?->files))
                ->columnSpanFull(),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                TextEntry::make('category')->label(__('documentacion.fields.category'))->badge()
                    ->formatStateUsing(fn (string $state): string => __("documentacion.categories.{$state}")),
                TextEntry::make('subject')->label(__('documentacion.fields.subject'))->placeholder('—'),
                TextEntry::make('document_date')->label(__('documentacion.fields.document_date'))->date('d/m/Y'),
                TextEntry::make('expires_at')->label(__('documentacion.fields.expires_at'))->placeholder('—')->badge()
                    ->formatStateUsing(fn (CompanyDocument $record): string => self::expiryText($record))
                    ->color(fn (CompanyDocument $record): string => self::expiryColor($record)),
                TextEntry::make('updated_at')->label(__('documentacion.fields.updated_at'))->dateTime('d/m/Y H:i'),
                TextEntry::make('notes')->label(__('documentacion.fields.notes'))->placeholder('—')->columnSpanFull()->extraAttributes(['style' => 'white-space: pre-line']),
                TextEntry::make('files')->label(__('documentacion.fields.files'))->columnSpanFull()->html()
                    ->state(fn (CompanyDocument $record): string => self::fileLinks($record)),
            ]),
            Section::make(__('documentacion.log'))->description(__('documentacion.log_help'))->collapsible()->schema([
                TextEntry::make('access_log')->hiddenLabel()->listWithLineBreaks()->placeholder(__('documentacion.log_empty'))
                    ->state(fn (CompanyDocument $record): array => $record->logs()->with('user')->limit(200)->get()
                        ->map(fn (CompanyDocumentLog $log): string => sprintf('%s · %s · %s · %s',
                            $log->created_at?->timezone(config('app.timezone'))->format('d/m/Y H:i'), $log->user->name ?? '—',
                            __("documentacion.actions.{$log->action}"), $log->file_name))->all()),
            ])->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->label(__('documentacion.fields.title'))->searchable(['title', 'subject', 'notes'])->wrap()->weight('medium'),
                TextColumn::make('category')->label(__('documentacion.fields.category'))->badge()
                    ->formatStateUsing(fn (string $state): string => __("documentacion.categories.{$state}")),
                TextColumn::make('subject')->label(__('documentacion.fields.subject'))->placeholder('—')->wrap(),
                TextColumn::make('document_date')->label(__('documentacion.fields.document_date'))->date('d/m/Y')->sortable(),
                TextColumn::make('expires_at')->label(__('documentacion.fields.expires_at'))->placeholder('—')->sortable()->badge()
                    ->formatStateUsing(fn (CompanyDocument $record): string => self::expiryText($record))
                    ->color(fn (CompanyDocument $record): string => self::expiryColor($record)),
            ])
            ->filters([
                SelectFilter::make('category')->label(__('documentacion.fields.category'))->options(CompanyDocument::categoryOptions()),
                Filter::make('expiring')->label(__('documentacion.filter_expiring'))
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('expires_at')->whereDate('expires_at', '<=', today()->addDays(CompanyDocument::WARN_DAYS))),
            ])
            ->recordUrl(fn (CompanyDocument $record): string => self::getUrl('view', ['record' => $record]))
            ->defaultSort('title');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCompanyDocuments::route('/'),
            'view' => ViewCompanyDocument::route('/{record}'),
        ];
    }

    public static function expiryText(CompanyDocument $record): string
    {
        $date = $record->expires_at?->format('d/m/Y') ?? '—';

        return match ($record->expiryStatus()) {
            'caducado' => $date.' · '.__('documentacion.expired'),
            'proximo' => $date.' · '.trans_choice('documentacion.expires_in', (int) today()->diffInDays($record->expires_at), ['days' => (int) today()->diffInDays($record->expires_at)]),
            default => $date,
        };
    }

    private static function expiryColor(CompanyDocument $record): string
    {
        return match ($record->expiryStatus()) {
            'caducado' => 'danger',
            'proximo' => 'warning',
            default => 'gray',
        };
    }

    /** «Ver» (inline) and «Descargar» for each file, both through the logged route. */
    private static function fileLinks(CompanyDocument $record): string
    {
        $html = '';
        foreach ((array) $record->files as $i => $file) {
            $url = route('clarto.documentacion.archivo', ['companyDocument' => $record->getKey(), 'index' => $i]);
            $html .= '<div style="margin-bottom:.35rem">'.e($file['name']).' <span style="opacity:.6">('.e(number_format($file['size'] / 1024 / 1024, 1, ',', '.')).' MB)</span> · '
                .'<a href="'.e($url).'" target="_blank" rel="noopener" style="text-decoration:underline">'.e(__('documentacion.view')).'</a> · '
                .'<a href="'.e($url.'?descargar=1').'" style="text-decoration:underline">'.e(__('documentacion.download')).'</a></div>';
        }

        return $html;
    }
}
