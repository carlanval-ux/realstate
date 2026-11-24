<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Documents\CompanyDocument;
use App\Documents\CompanyDocumentFolder;
use App\Documents\CompanyDocumentLog;
use App\Documents\CompanyDocumentVote;
use App\Filament\Resources\CompanyDocumentResource\Pages\ManageCompanyDocuments;
use App\Filament\Resources\CompanyDocumentResource\Pages\ViewCompanyDocument;
use Filament\Facades\Filament;
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
 * «Documentación de la empresa» (menu Organización, /admin only): the company's official documents, in folders, for its
 * responsables (administrators and owner). Registered from ClartoCompanyDocumentsServiceProvider; never in global
 * search, the app panel or the API.
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
            Select::make('folder_id')->label(__('documentacion.fields.folder'))->required()->searchable()
                ->options(fn (): array => CompanyDocumentFolder::options(Filament::getTenant()?->getKey())),
            TextInput::make('subject')->label(__('documentacion.fields.subject'))->maxLength(200)->helperText(__('documentacion.subject_help')),
            DatePicker::make('document_date')->label(__('documentacion.fields.document_date'))->required()->displayFormat('d/m/Y'),
            DatePicker::make('expires_at')->label(__('documentacion.fields.expires_at'))->displayFormat('d/m/Y')->helperText(__('documentacion.expires_help')),
            Textarea::make('notes')->label(__('documentacion.fields.notes'))->maxLength(5000)->rows(4)->columnSpanFull(),
            self::filesField()->required(fn (?CompanyDocument $record): bool => blank($record?->files)),
            CheckboxList::make('remove_files')->label(__('documentacion.fields.remove_files'))
                ->options(fn (?CompanyDocument $record): array => array_map(fn (array $file): string => $file['name'], (array) $record?->files))
                ->visible(fn (?CompanyDocument $record): bool => filled($record?->files))
                ->columnSpanFull(),
        ]);
    }

    /** New files (document form and «signed copy»): checked again on the server by CompanyDocument. */
    public static function filesField(): FileUpload
    {
        return FileUpload::make('new_files')->label(__('documentacion.fields.new_files'))
            ->helperText(__('documentacion.upload_help', ['max' => CompanyDocument::MAX_FILES, 'mb' => CompanyDocument::MAX_BYTES / 1024 / 1024]))
            ->multiple()->appendFiles()
            ->acceptedFileTypes(CompanyDocument::MIME_TYPES)
            ->maxSize(CompanyDocument::MAX_BYTES / 1024)
            ->maxFiles(CompanyDocument::MAX_FILES)
            ->disk(CompanyDocument::DISK)
            ->directory(fn (): string => CompanyDocument::uploadDirectory((int) auth()->id()))
            ->visibility('private')
            ->storeFileNamesIn('new_file_names')
            ->columnSpanFull();
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                TextEntry::make('folder')->label(__('documentacion.fields.folder'))->state(fn (CompanyDocument $record): string => (string) $record->folder?->path()),
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
            Section::make(__('documentacion.approval'))->columns(3)->visible(fn (CompanyDocument $record): bool => $record->status !== null)->schema([
                TextEntry::make('status')->label(__('documentacion.fields.status'))->badge()
                    ->formatStateUsing(fn (?string $state): string => CompanyDocument::statusLabel($state))->color(fn (?string $state): string => self::statusColor($state)),
                TextEntry::make('progress')->label(__('documentacion.fields.progress'))->placeholder('—')
                    ->state(fn (CompanyDocument $record): ?string => $record->approvalProgress()),
                TextEntry::make('manager.name')->label(__('documentacion.fields.manager'))->placeholder('—'),
                TextEntry::make('vote_list')->label(__('documentacion.fields.votes'))->columnSpanFull()->listWithLineBreaks()->placeholder(__('documentacion.no_votes'))
                    ->state(fn (CompanyDocument $record): array => $record->votes()->with('user')->get()
                        ->map(fn (CompanyDocumentVote $vote): string => sprintf('%s · %s%s', $vote->user->name ?? '—',
                            __($vote->approved ? 'documentacion.vote_yes' : 'documentacion.vote_no'), filled($vote->comment) ? ' · «'.$vote->comment.'»' : ''))->all()),
            ])->columnSpanFull(),
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
                // ponytail: one folder query per row; fine for a company's archive, cache the options if the list grows.
                TextColumn::make('folder_id')->label(__('documentacion.fields.folder'))->wrap()
                    ->formatStateUsing(fn (CompanyDocument $record): string => CompanyDocumentFolder::options($record->team_id)[$record->folder_id] ?? '—'),
                TextColumn::make('subject')->label(__('documentacion.fields.subject'))->placeholder('—')->wrap(),
                TextColumn::make('status')->label(__('documentacion.fields.status'))->badge()->placeholder('—')
                    ->formatStateUsing(fn (CompanyDocument $record): string => CompanyDocument::statusLabel($record->status)
                        .($record->status === CompanyDocument::PENDING ? ' · '.$record->approvalProgress() : ''))
                    ->color(fn (?string $state): string => self::statusColor($state)),
                TextColumn::make('document_date')->label(__('documentacion.fields.document_date'))->date('d/m/Y')->sortable(),
                TextColumn::make('expires_at')->label(__('documentacion.fields.expires_at'))->placeholder('—')->sortable()->badge()
                    ->formatStateUsing(fn (CompanyDocument $record): string => self::expiryText($record))
                    ->color(fn (CompanyDocument $record): string => self::expiryColor($record)),
            ])
            ->filters([
                SelectFilter::make('folder')->label(__('documentacion.fields.folder'))->searchable()
                    ->options(fn (): array => CompanyDocumentFolder::options(Filament::getTenant()?->getKey()))
                    ->query(function (Builder $query, array $data): Builder {   // the folder and its subfolders
                        $folder = filled($data['value'] ?? null) ? CompanyDocumentFolder::query()->find($data['value']) : null;

                        return $folder === null ? $query : $query->whereIn('folder_id', $folder->withDescendantIds());
                    }),
                SelectFilter::make('status')->label(__('documentacion.fields.status'))
                    ->options(array_combine(CompanyDocument::STATUSES, array_map(CompanyDocument::statusLabel(...), CompanyDocument::STATUSES))),
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

    private static function statusColor(?string $status): string
    {
        return match ($status) {
            CompanyDocument::PENDING => 'warning',
            CompanyDocument::APPROVED => 'info',
            CompanyDocument::SIGNED => 'success',
            default => 'gray',
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
