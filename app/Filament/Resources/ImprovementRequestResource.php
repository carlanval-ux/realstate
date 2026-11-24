<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ImprovementRequestResource\Pages\ManageImprovementRequests;
use App\Filament\Resources\ImprovementRequestResource\Pages\ViewImprovementRequest;
use App\Models\ImprovementRequest;
use App\Models\ImprovementRequestEvent;
use App\Models\User;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * «Incidencias y mejoras» (menu group Start): the team's forum to report incidents and ask for improvements or
 * changes of the tool. Threads and replies carry text + photos; everyone in the company reads and replies, the
 * company's administrators move threads through Received → In review → Planned → Implemented / Discarded.
 * Registered on the admin and app panels from ClartoServiceProvider.
 */
final class ImprovementRequestResource extends Resource
{
    protected static ?string $model = ImprovementRequest::class;

    protected static ?string $slug = 'solicitud-mejoras';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    /** First entry of «Procesos y conocimiento» (owner, 2026-11-24): before the processes home (sort 1). */
    protected static ?int $navigationSort = 0;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getNavigationGroup(): ?string
    {
        return \App\Filament\Processes\ProcessUi::group();
    }

    public static function getNavigationLabel(): string
    {
        return __('clarto.mejoras.menu');
    }

    public static function getModelLabel(): string
    {
        return __('clarto.mejoras.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('clarto.mejoras.plural');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('user')->visibleTo(auth()->user())
            ->withCount(['events as replies_count' => fn (Builder $q) => $q->where(fn (Builder $q) => $q->whereNotNull('body')->orWhereNotNull('attachments'))]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('type')->label(__('clarto.mejoras.fields.type'))->options(ImprovementRequest::typeOptions())->required()->default('incidencia')->native(false),
            TextInput::make('title')->label(__('clarto.mejoras.fields.title'))->required()->maxLength(160)->columnSpanFull(),
            Textarea::make('description')->label(__('clarto.mejoras.fields.description'))->required()->maxLength(5000)->rows(6)->columnSpanFull(),
            self::photosField(),
        ]);
    }

    /** Photos of a thread or a reply: previewed and removable before sending, checked again on the server. */
    public static function photosField(): FileUpload
    {
        return FileUpload::make('attachments')->label(__('clarto.mejoras.fields.attachments'))
            ->helperText(__('clarto.mejoras.upload_help', ['max' => ImprovementRequest::MAX_FILES, 'mb' => ImprovementRequest::MAX_BYTES / 1024 / 1024]))
            ->multiple()
            ->appendFiles()
            ->image()
            ->acceptedFileTypes(ImprovementRequest::MIME_TYPES)
            ->maxSize(ImprovementRequest::MAX_BYTES / 1024)
            ->maxFiles(ImprovementRequest::MAX_FILES)
            ->disk(ImprovementRequest::DISK)
            ->directory(fn (): string => ImprovementRequest::uploadDirectory((int) auth()->id()))
            ->visibility('private')
            ->storeFileNamesIn('attachment_names')
            ->panelLayout('grid')
            ->imagePreviewHeight('140')
            ->columnSpanFull();
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('type')->label(__('clarto.mejoras.fields.type'))->badge()
                    ->formatStateUsing(fn (string $state): string => ImprovementRequest::typeLabel($state))->color(fn (string $state): string => self::typeColor($state)),
                TextEntry::make('status')->label(__('clarto.mejoras.fields.status'))->badge()
                    ->formatStateUsing(fn (string $state): string => ImprovementRequest::statusLabel($state))->color(fn (string $state): string => self::statusColor($state)),
                TextEntry::make('user.name')->label(__('clarto.mejoras.fields.author')),
                TextEntry::make('created_at')->label(__('clarto.mejoras.fields.created_at'))->dateTime('d/m/Y H:i'),
                TextEntry::make('description')->label(__('clarto.mejoras.fields.description'))->columnSpanFull()->extraAttributes(['style' => 'white-space: pre-line']),
                TextEntry::make('attachments')->label(__('clarto.mejoras.fields.attachments'))->columnSpanFull()->html()
                    ->visible(fn (ImprovementRequest $record): bool => filled($record->attachments))
                    ->state(fn (ImprovementRequest $record): string => self::thumbnails($record)),
            ]),
            RepeatableEntry::make('events')->label(__('clarto.mejoras.history'))->contained(false)->schema([
                TextEntry::make('summary')->hiddenLabel()->weight('medium')
                    ->state(fn (ImprovementRequestEvent $record): string => self::eventSummary($record)),
                TextEntry::make('body')->hiddenLabel()->visible(fn (ImprovementRequestEvent $record): bool => filled($record->body))
                    ->extraAttributes(['style' => 'white-space: pre-line']),
                TextEntry::make('attachments')->hiddenLabel()->html()
                    ->visible(fn (ImprovementRequestEvent $record): bool => filled($record->attachments))
                    ->state(fn (ImprovementRequestEvent $record): string => self::thumbnails($record->thread, $record)),
            ])->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')->label(__('clarto.mejoras.fields.type'))->badge()
                    ->formatStateUsing(fn (string $state): string => ImprovementRequest::typeLabel($state))->color(fn (string $state): string => self::typeColor($state)),
                TextColumn::make('title')->label(__('clarto.mejoras.fields.title'))->searchable(['title', 'description'])->limit(70)->wrap()->weight('medium'),
                TextColumn::make('user.name')->label(__('clarto.mejoras.fields.author')),
                TextColumn::make('status')->label(__('clarto.mejoras.fields.status'))->badge()
                    ->formatStateUsing(fn (string $state): string => ImprovementRequest::statusLabel($state))->color(fn (string $state): string => self::statusColor($state)),
                TextColumn::make('replies_count')->label(__('clarto.mejoras.fields.replies'))->numeric()->sortable()->alignCenter(),
                TextColumn::make('updated_at')->label(__('clarto.mejoras.fields.updated_at'))->since()->sortable(),
                TextColumn::make('created_at')->label(__('clarto.mejoras.fields.created_at'))->dateTime('d/m/Y H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')->label(__('clarto.mejoras.fields.type'))->options(ImprovementRequest::typeOptions()),
                SelectFilter::make('status')->label(__('clarto.mejoras.fields.status'))->options(ImprovementRequest::statusOptions()),
                // Only authors of threads the user can see: never a list of other companies' people.
                SelectFilter::make('user_id')->label(__('clarto.mejoras.fields.author'))->searchable()
                    ->options(fn (): array => User::query()->whereIn('id', ImprovementRequest::query()->visibleTo(auth()->user())->select('user_id'))->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->recordUrl(fn (ImprovementRequest $record): string => self::getUrl('view', ['record' => $record]))
            ->defaultSort('updated_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageImprovementRequests::route('/'),
            'view' => ViewImprovementRequest::route('/{record}'),
        ];
    }

    private static function typeColor(string $type): string
    {
        return match ($type) {
            'incidencia' => 'danger',
            'modificacion' => 'warning',
            default => 'info',
        };
    }

    private static function statusColor(string $status): string
    {
        return match ($status) {
            'in_review' => 'warning',
            'planned' => 'info',
            'implemented' => 'success',
            'discarded' => 'danger',
            default => 'gray',
        };
    }

    private static function eventSummary(ImprovementRequestEvent $event): string
    {
        $who = $event->user?->name ?? '—';
        $when = $event->created_at?->timezone(config('app.timezone'))->format('d/m/Y H:i') ?? '';
        $what = match (true) {
            $event->from_status === null && $event->to_status !== null => __('clarto.mejoras.events.created'),
            $event->to_status !== null => __('clarto.mejoras.events.status', ['from' => ImprovementRequest::statusLabel($event->from_status), 'to' => ImprovementRequest::statusLabel($event->to_status)]),
            default => __('clarto.mejoras.events.reply'),
        };

        return "{$when} · {$who} · {$what}";
    }

    /** Thumbnails that open the full-size photo (through the authorised route) in a new tab. */
    private static function thumbnails(ImprovementRequest $record, ?ImprovementRequestEvent $event = null): string
    {
        $html = '';
        foreach ((array) ($event ?? $record)->attachments as $i => $file) {
            $url = e(route('clarto.mejoras.adjunto', ['improvementRequest' => $record->getKey(), 'index' => $i] + ($event ? ['respuesta' => $event->getKey()] : [])));
            $name = e($file['name'] ?? '');
            $html .= "<a href=\"{$url}\" target=\"_blank\" rel=\"noopener\" title=\"{$name}\" style=\"display:inline-block;margin:0 .5rem .5rem 0\">"
                ."<img src=\"{$url}\" alt=\"{$name}\" loading=\"lazy\" style=\"height:120px;max-width:200px;object-fit:cover;border-radius:.5rem;border:1px solid rgba(0,0,0,.1)\"></a>";
        }

        return $html;
    }
}
