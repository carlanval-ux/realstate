<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Documents\CompanyDocument;
use App\Documents\CompanyDocumentAnnotation;
use App\Documents\CompanyDocumentFolder;
use App\Documents\CompanyDocumentLog;
use App\Documents\CompanyDocumentVote;
use App\Filament\Resources\CompanyDocumentResource\Pages\ManageCompanyDocuments;
use App\Filament\Resources\CompanyDocumentResource\Pages\ViewCompanyDocument;
use App\Filament\Resources\CompanyDocumentResource\Pages\ViewCompanyDocumentFile;
use Closure;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Colors\Color;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

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
            // «Nuevo expediente» from the folder browser starts in the open folder.
            Select::make('folder_id')->label(__('documentacion.fields.folder'))->required()->searchable()
                ->options(fn (): array => CompanyDocumentFolder::options(Filament::getTenant()?->getKey()))
                ->default(fn (mixed $livewire): ?int => $livewire instanceof ManageCompanyDocuments ? $livewire->folderForNewDocument() : null)
                ->createOptionForm([
                    TextInput::make('name')->label(__('documentacion.folders.name'))->required()->maxLength(120),
                    Select::make('parent_id')->label(__('documentacion.folders.parent'))->placeholder(__('documentacion.folders.root'))->searchable()
                        ->options(fn (): array => CompanyDocumentFolder::options(Filament::getTenant()?->getKey()))
                        ->default(fn (mixed $livewire): ?int => $livewire instanceof ManageCompanyDocuments ? $livewire->folderForNewDocument() : null),
                ])
                ->createOptionModalHeading(__('documentacion.folders.new'))
                ->createOptionUsing(fn (array $data): int => (int) CompanyDocumentFolder::createFor(
                    auth()->user(), Filament::getTenant()?->getKey(), (string) $data['name'], $data['parent_id'] ?? null,
                )->getKey()),
            TextInput::make('subject')->label(__('documentacion.fields.subject'))->maxLength(200)->helperText(__('documentacion.subject_help')),
            DatePicker::make('document_date')->label(__('documentacion.fields.document_date'))->required()->displayFormat('d/m/Y'),
            DatePicker::make('expires_at')->label(__('documentacion.fields.expires_at'))->displayFormat('d/m/Y')->helperText(__('documentacion.expires_help')),
            Textarea::make('explanation')->label(__('documentacion.fields.explanation'))->helperText(__('documentacion.explanation_help'))
                ->maxLength(CompanyDocument::MAX_EXPLANATION)->rows(5)->autosize()->columnSpanFull(),
            // Editing: the files it already has, one row each under its original name (deleting the row removes the
            // file; the description stays optional). They are never put in a FileUpload, so no temporary URL skips the log.
            Repeater::make('items')->label(__('documentacion.fields.files'))->helperText(__('documentacion.files_existing_help'))
                ->schema([
                    Hidden::make('index'),
                    Hidden::make('name'),
                    TextInput::make('description')->label(__('documentacion.fields.description'))->maxLength(CompanyDocument::MAX_DESCRIPTION)
                        ->placeholder(__('documentacion.description_placeholder')),
                ])
                ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                ->addable(false)->reorderable(false)->minItems(0)->defaultItems(0)
                ->visibleOn('edit')->columnSpanFull(),
            // New files, several at once (drag and drop), kept under their original names. Required when creating.
            self::filesField()->label(fn (string $operation): string => __($operation === 'create' ? 'documentacion.fields.files' : 'documentacion.fields.new_files'))
                ->required(fn (string $operation): bool => $operation === 'create'),
            // Only when creating: approval decided at upload (CompanyDocument::saveFor → requestApproval). Later, the
            // record's «Pedir aprobación». Without anyone else to vote, the switch is off and says why.
            Toggle::make('requires_approval')->label(__('documentacion.upload_approval.question'))->default(false)->live()
                ->visibleOn('create')->disabled(fn (): bool => self::votersForCurrentUser() === 0)
                ->helperText(fn (): string => self::votersForCurrentUser() === 0 ? __('documentacion.upload_approval.no_voters')
                    : __('documentacion.upload_approval.help', ['voters' => self::votersForCurrentUser()])),
            ...self::approvalFields(self::votersForCurrentUser(...),
                fn (Get $get, string $operation): bool => $operation === 'create' && (bool) $get('requires_approval') && self::votersForCurrentUser() > 0),
        ]);
    }

    /**
     * «Regla» (number of approvals, or absolute majority with the number it gives: «Mayoría absoluta: 3 de 5») and, for
     * the number, «Aprobaciones necesarias» (1 … voters). The new expediente form and «Pedir aprobación» use them.
     *
     * @param  Closure(): int  $voters  who would vote if the current user asked
     * @param  Closure|null  $visible  evaluated by Filament (named injection: $get, $operation…); null = always
     * @return list<Radio|TextInput>
     */
    public static function approvalFields(Closure $voters, ?Closure $visible = null): array
    {
        $shown = fn (Component $component): bool => $visible === null || (bool) $component->evaluate($visible);

        return [
            Radio::make('approval_rule')->label(__('documentacion.fields.approval_rule'))->required()->live()->default(CompanyDocument::RULE_NUMBER)
                ->options([CompanyDocument::RULE_NUMBER => __('documentacion.rules.numero'), CompanyDocument::RULE_MAJORITY => __('documentacion.rules.mayoria')])
                ->descriptions(fn (): array => [
                    CompanyDocument::RULE_NUMBER => __('documentacion.rules.numero_help', ['voters' => $voters()]),
                    CompanyDocument::RULE_MAJORITY => __('documentacion.rules.mayoria_help', ['required' => CompanyDocument::majority($voters()), 'voters' => $voters()]),
                ])
                ->visible($shown),
            TextInput::make('approvals_required')->label(__('documentacion.fields.approvals_required'))->integer()->required()
                ->minValue(1)->maxValue(fn (): int => max(1, $voters()))->default(fn (): int => max(1, $voters()))
                ->visible(fn (Component $component, Get $get): bool => $shown($component) && $get('approval_rule') !== CompanyDocument::RULE_MAJORITY),
        ];
    }

    /** Responsables of the current company who would vote if the current user asked for approval. */
    public static function votersForCurrentUser(): int
    {
        $teamId = Filament::getTenant()?->getKey();

        return $teamId === null ? 0 : CompanyDocument::otherResponsables($teamId, auth()->id())->count();
    }

    /** @return list<array{index: int, name: string, description: string|null}> the form rows of an expediente's files */
    public static function fileItems(CompanyDocument $record): array
    {
        return array_map(fn (int $i, array $file): array => ['index' => $i, 'name' => $file['name'], 'description' => $file['description'] ?? null],
            array_keys((array) $record->files), array_values((array) $record->files));
    }

    /**
     * Form → CompanyDocument::saveFor data: rows of existing files are kept (with their description), missing ones
     * removed; new_files / new_file_names (the multiple upload, original names) pass through.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fromForm(array $data, ?CompanyDocument $record = null): array
    {
        $kept = [];
        foreach ((array) ($data['items'] ?? []) as $item) {
            if (isset($item['index']) && $item['index'] !== '') {
                $kept[(int) $item['index']] = $item['description'] ?? null;
            }
        }
        unset($data['items']);

        return $data + [
            'file_descriptions' => $kept,
            'remove_files' => array_values(array_diff(array_keys((array) $record?->files), array_keys($kept))),
        ];
    }

    /** The upload field (an annotation's one attachment; multiple in filesField): checked again on the server by CompanyDocument. */
    public static function uploadField(string $name): FileUpload
    {
        return FileUpload::make($name)
            // Browser: types plus extensions (the file picker) and an extension → type map (FilePond, when the system
            // gives a .docx no type). The same list is Filament's own `mimetypes` rule on the server, where libmagic
            // may see a .docx / .xlsx as a zip: zip passes that rule and checkUploads decides by the zip's contents.
            ->acceptedFileTypes([...CompanyDocument::MIME_TYPES, 'application/zip', ...array_map(fn (string $ext): string => ".{$ext}", array_keys(CompanyDocument::OFFICE_TYPES))])
            ->mimeTypeMap(CompanyDocument::OFFICE_TYPES)
            ->maxSize(CompanyDocument::MAX_BYTES / 1024)
            ->disk(CompanyDocument::DISK)
            ->directory(fn (): string => CompanyDocument::uploadDirectory((int) auth()->id()))
            ->visibility('private');
    }

    public static function uploadHelp(): string
    {
        return __('documentacion.upload_help', ['max' => CompanyDocument::MAX_FILES, 'mb' => CompanyDocument::MAX_BYTES / 1024 / 1024]);
    }

    /** Several new files at once (the expediente's form, the «signed copy»): checked again on the server by CompanyDocument. */
    public static function filesField(): FileUpload
    {
        return self::uploadField('new_files')->label(__('documentacion.fields.new_files'))->helperText(self::uploadHelp())
            ->multiple()->appendFiles()->maxFiles(CompanyDocument::MAX_FILES)
            ->storeFileNamesIn('new_file_names')
            ->columnSpanFull();
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                // First on the record, line breaks kept; built here with the text escaped (like the annotation thread).
                TextEntry::make('explanation')->label(__('documentacion.fields.explanation'))->columnSpanFull()
                    ->visible(fn (CompanyDocument $record): bool => filled($record->explanation))
                    ->state(fn (CompanyDocument $record): HtmlString => new HtmlString('<div style="white-space:pre-line">'.e((string) $record->explanation).'</div>')),
                TextEntry::make('folder')->label(__('documentacion.fields.folder'))->state(fn (CompanyDocument $record): string => (string) $record->folder?->path()),
                TextEntry::make('subject')->label(__('documentacion.fields.subject'))->placeholder('—'),
                TextEntry::make('document_date')->label(__('documentacion.fields.document_date'))->date('d/m/Y'),
                TextEntry::make('expires_at')->label(__('documentacion.fields.expires_at'))->placeholder('—')->badge()
                    ->formatStateUsing(fn (CompanyDocument $record): string => self::expiryText($record))
                    ->color(fn (CompanyDocument $record): string => self::expiryColor($record)),
                TextEntry::make('updated_at')->label(__('documentacion.fields.updated_at'))->dateTime('d/m/Y H:i'),
                TextEntry::make('files')->label(__('documentacion.fields.files'))->columnSpanFull()->html()
                    ->state(fn (CompanyDocument $record): string => self::fileLinks($record)),
            ]),
            Section::make(__('documentacion.annotations'))->description(__('documentacion.annotations_help', ['minutes' => CompanyDocumentAnnotation::DELETE_MINUTES]))->schema([
                // Htmlable: rendered as built here (every user text escaped), so the «Borrar» button keeps its wire:click.
                TextEntry::make('annotation_thread')->hiddenLabel()->placeholder(__('documentacion.annotations_empty'))
                    ->state(fn (CompanyDocument $record): HtmlString => self::thread($record)),
            ])->columnSpanFull(),
            Section::make(__('documentacion.approval'))->columns(3)
                ->description(fn (CompanyDocument $record): ?string => match ($record->status) {
                    CompanyDocument::AVAILABLE => __('documentacion.available_help'),
                    CompanyDocument::REJECTED => __('documentacion.rejected_help'),
                    default => null,
                })->schema([
                    TextEntry::make('status')->label(__('documentacion.fields.status'))->badge()
                        ->formatStateUsing(fn (?string $state): string => CompanyDocument::statusLabel($state))->color(fn (?string $state): string|array => self::statusColor($state)),
                    TextEntry::make('progress')->label(__('documentacion.fields.progress'))->placeholder('—')->visible(fn (CompanyDocument $record): bool => self::inApproval($record))
                        ->state(fn (CompanyDocument $record): ?string => $record->approvalProgress()),
                    TextEntry::make('manager.name')->label(__('documentacion.fields.manager'))->placeholder('—')->visible(fn (CompanyDocument $record): bool => self::inApproval($record)),
                    // Every condition together, with its author: what has to be met before closing it.
                    TextEntry::make('condition_list')->label(__('documentacion.fields.conditions'))->columnSpanFull()->listWithLineBreaks()
                        ->visible(fn (CompanyDocument $record): bool => self::inApproval($record) && $record->conditionVotes()->isNotEmpty())
                        ->state(fn (CompanyDocument $record): array => $record->conditionVotes()
                            ->map(fn (CompanyDocumentVote $vote): string => sprintf('%s: «%s»', $vote->user->name ?? '—', $vote->comment))->all()),
                    TextEntry::make('vote_list')->label(__('documentacion.fields.votes'))->columnSpanFull()->listWithLineBreaks()->placeholder(__('documentacion.no_votes'))
                        ->visible(fn (CompanyDocument $record): bool => self::inApproval($record))
                        ->state(fn (CompanyDocument $record): array => $record->votes()->with('user')->get()
                            ->map(fn (CompanyDocumentVote $vote): string => sprintf('%s · %s%s', $vote->user->name ?? '—',
                                __("documentacion.decisions.{$vote->decision}"), filled($vote->comment) ? ' · «'.$vote->comment.'»' : ''))->all()),
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
                TextColumn::make('title')->label(__('documentacion.fields.title'))->wrap()->weight('medium')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $q): Builder => $q
                        ->where('title', 'like', "%{$search}%")->orWhere('subject', 'like', "%{$search}%")->orWhere('explanation', 'like', "%{$search}%")
                        ->orWhereHas('annotations', fn (Builder $a): Builder => $a->where('body', 'like', "%{$search}%")))),
                // ponytail: one folder query per row; fine for a company's archive, cache the options if the list grows.
                TextColumn::make('folder_id')->label(__('documentacion.fields.folder'))->wrap()
                    ->formatStateUsing(fn (CompanyDocument $record): string => CompanyDocumentFolder::options($record->team_id)[$record->folder_id] ?? '—'),
                TextColumn::make('subject')->label(__('documentacion.fields.subject'))->placeholder('—')->wrap(),
                TextColumn::make('status')->label(__('documentacion.fields.status'))->badge()->placeholder('—')
                    ->formatStateUsing(fn (CompanyDocument $record): string => CompanyDocument::statusLabel($record->status)
                        .(in_array($record->status, [CompanyDocument::PENDING, CompanyDocument::REJECTED], true) ? ' · '.$record->approvalProgress() : ''))
                    ->color(fn (?string $state): string|array => self::statusColor($state)),
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
            'visor' => ViewCompanyDocumentFile::route('/{record}/visor/{file}'),
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

    /**
     * Badge colour of each status: Disponible gray, Pendiente amber, Aprobado blue, Aprobado con condiciones indigo (a
     * palette of its own: the panel has no sixth named colour), Firmado green, Rechazado red.
     *
     * @return string|array<int|string, string>
     */
    public static function statusColor(?string $status): string|array
    {
        return match ($status) {
            CompanyDocument::AVAILABLE => 'gray',
            CompanyDocument::PENDING => 'warning',
            CompanyDocument::APPROVED => 'info',
            CompanyDocument::APPROVED_CONDITIONS => Color::Indigo,
            CompanyDocument::SIGNED => 'success',
            CompanyDocument::REJECTED => 'danger',
            default => 'gray',
        };
    }

    /** It has (or had) an approval: progress, manager and answers are shown. A «Disponible» one never had any. */
    private static function inApproval(CompanyDocument $record): bool
    {
        return $record->status !== CompanyDocument::AVAILABLE;
    }

    private static function expiryColor(CompanyDocument $record): string
    {
        return match ($record->expiryStatus()) {
            'caducado' => 'danger',
            'proximo' => 'warning',
            default => 'gray',
        };
    }

    /** Each file by its description (or name), with «Ver» (the web viewer) and «Descargar» (the logged route). */
    private static function fileLinks(CompanyDocument $record): string
    {
        $html = '';
        foreach ((array) $record->files as $i => $file) {
            $url = route('clarto.documentacion.archivo', ['companyDocument' => $record->getKey(), 'index' => $i]);
            $html .= '<div style="margin-bottom:.35rem">'.self::fileLine(CompanyDocument::fileLabel($file), $file['size'], $url, self::viewerUrl($record, "archivo-{$i}")).'</div>';
        }

        return $html;
    }

    /** The annotations, oldest first: date · author, text, attachment links; «Borrar» on the viewer's own recent ones. */
    private static function thread(CompanyDocument $record): HtmlString
    {
        $html = '';
        foreach ($record->annotations()->with('user')->get() as $annotation) {
            $html .= '<div style="padding:.6rem 0;border-top:1px solid rgba(127,127,127,.25)">'
                .'<div style="opacity:.7;font-size:.875em">'.e($annotation->created_at?->timezone(config('app.timezone'))->format('d/m/Y H:i')).' · '.e($annotation->user->name ?? '—').'</div>'
                .'<div style="white-space:pre-line">'.e($annotation->body).'</div>';
            if ($annotation->file !== null) {
                $url = route('clarto.documentacion.anotacion', ['companyDocument' => $record->getKey(), 'annotation' => $annotation->getKey()]);
                $html .= '<div style="margin-top:.25rem">📎 '.self::fileLine($annotation->file['name'], $annotation->file['size'], $url, self::viewerUrl($record, "anotacion-{$annotation->getKey()}")).'</div>';
            }
            if ($annotation->canBeDeletedBy(auth()->user())) {
                $html .= '<button type="button" wire:click="mountAction(\'deleteAnnotation\', { annotation: '.(int) $annotation->getKey().' })" '
                    .'style="margin-top:.25rem;font-size:.875em;color:rgb(220,38,38);text-decoration:underline">'.e(__('documentacion.annotation.delete')).'</button>';
            }
            $html .= '</div>';
        }

        return new HtmlString($html);
    }

    /**
     * «Ver» opens the web viewer for every file (PDF and images as they are, Word / Excel / OpenDocument converted to
     * PDF; what cannot be shown says so there); «Descargar» goes through the logged route.
     */
    private static function fileLine(string $label, int $size, string $url, string $viewer): string
    {
        return e($label).' <span style="opacity:.6">('.e(number_format($size / 1024 / 1024, 1, ',', '.')).' MB)</span> · '
            .'<a href="'.e($viewer).'" style="text-decoration:underline">'.e(__('documentacion.view')).'</a> · '
            .'<a href="'.e($url.'?descargar=1').'" style="text-decoration:underline">'.e(__('documentacion.download')).'</a>';
    }

    /** The web viewer of one file of the expediente (archivo-{index} / anotacion-{id}). */
    public static function viewerUrl(CompanyDocument $record, string $file): string
    {
        return self::getUrl('visor', ['record' => $record, 'file' => $file]);
    }
}
