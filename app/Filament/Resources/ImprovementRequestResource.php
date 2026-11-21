<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ImprovementRequestResource\Pages\ManageImprovementRequests;
use App\Models\ImprovementRequest;
use App\Models\ImprovementRequestEvent;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * «Solicitud de mejoras» (menu group Start): the team proposes improvements with optional screenshots,
 * administrators of the team answer and move them through Received → In review → Planned → Implemented /
 * Discarded. Registered on both panels from ClartoServiceProvider.
 */
final class ImprovementRequestResource extends Resource
{
    protected static ?string $model = ImprovementRequest::class;

    protected static ?string $slug = 'solicitud-mejoras';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-light-bulb';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getNavigationGroup(): ?string
    {
        return __('Start');
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
        return parent::getEloquentQuery()->with('user')->visibleTo(auth()->user());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->label(__('clarto.mejoras.fields.title'))->required()->maxLength(160)->columnSpanFull(),
            Textarea::make('description')->label(__('clarto.mejoras.fields.description'))->required()->maxLength(5000)->rows(6)->columnSpanFull(),
            FileUpload::make('attachments')->label(__('clarto.mejoras.fields.attachments'))
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
                ->columnSpanFull(),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                TextEntry::make('status')->label(__('clarto.mejoras.fields.status'))->badge()
                    ->formatStateUsing(fn (string $state): string => ImprovementRequest::statusLabel($state))->color(fn (string $state): string => self::statusColor($state)),
                TextEntry::make('user.name')->label(__('clarto.mejoras.fields.author')),
                TextEntry::make('created_at')->label(__('clarto.mejoras.fields.created_at'))->dateTime(),
                TextEntry::make('description')->label(__('clarto.mejoras.fields.description'))->columnSpanFull()->extraAttributes(['style' => 'white-space: pre-line']),
                TextEntry::make('attachments')->label(__('clarto.mejoras.fields.attachments'))->columnSpanFull()->html()
                    ->visible(fn (ImprovementRequest $record): bool => filled($record->attachments))
                    ->state(fn (ImprovementRequest $record): string => self::thumbnails($record)),
            ]),
            RepeatableEntry::make('events')->label(__('clarto.mejoras.history'))->contained(false)->schema([
                TextEntry::make('summary')->hiddenLabel()
                    ->state(fn (ImprovementRequestEvent $record): string => self::eventSummary($record)),
                TextEntry::make('body')->hiddenLabel()->visible(fn (ImprovementRequestEvent $record): bool => filled($record->body))
                    ->extraAttributes(['style' => 'white-space: pre-line']),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->label(__('clarto.mejoras.fields.title'))->searchable()->limit(60)->wrap(),
                TextColumn::make('user.name')->label(__('clarto.mejoras.fields.author')),
                TextColumn::make('status')->label(__('clarto.mejoras.fields.status'))->badge()
                    ->formatStateUsing(fn (string $state): string => ImprovementRequest::statusLabel($state))->color(fn (string $state): string => self::statusColor($state)),
                TextColumn::make('created_at')->label(__('clarto.mejoras.fields.created_at'))->dateTime()->sortable(),
                TextColumn::make('updated_at')->label(__('clarto.mejoras.fields.updated_at'))->since()->sortable(),
            ])
            ->filters([SelectFilter::make('status')->label(__('clarto.mejoras.fields.status'))->options(ImprovementRequest::statusOptions())])
            ->recordActions([
                ViewAction::make(),
                Action::make('respond')->label(__('clarto.mejoras.respond'))->icon('heroicon-o-chat-bubble-left-ellipsis')
                    ->visible(fn (ImprovementRequest $record): bool => (bool) auth()->user()?->can('update', $record))
                    ->fillForm(fn (ImprovementRequest $record): array => ['status' => $record->status])
                    ->schema([
                        Select::make('status')->label(__('clarto.mejoras.fields.status'))->options(ImprovementRequest::statusOptions())->required(),
                        Textarea::make('reply')->label(__('clarto.mejoras.fields.reply'))->maxLength(5000)->rows(5),
                    ])
                    ->action(function (ImprovementRequest $record, array $data): void {
                        $record->respond(auth()->user(), $data['status'], $data['reply'] ?? null);
                        Notification::make()->title(__('clarto.mejoras.saved'))->success()->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageImprovementRequests::route('/')];
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

    private static function thumbnails(ImprovementRequest $record): string
    {
        $html = '';
        foreach ((array) $record->attachments as $i => $file) {
            $url = e(route('clarto.mejoras.adjunto', ['improvementRequest' => $record->getKey(), 'index' => $i]));
            $name = e($file['name'] ?? '');
            $html .= "<a href=\"{$url}\" target=\"_blank\" rel=\"noopener\" title=\"{$name}\" style=\"display:inline-block;margin:0 .5rem .5rem 0\">"
                ."<img src=\"{$url}\" alt=\"{$name}\" style=\"height:120px;max-width:200px;object-fit:cover;border-radius:.5rem;border:1px solid rgba(0,0,0,.1)\"></a>";
        }

        return $html;
    }
}
