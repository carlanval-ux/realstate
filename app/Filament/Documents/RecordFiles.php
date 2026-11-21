<?php

declare(strict_types=1);

namespace App\Filament\Documents;

use App\Documents\RecordDocument;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Liberu\RealEstate\Parties\Models\Party;
use Liberu\RealEstate\Properties\Models\Property;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The «Documents» list of one client or property: upload (category, private disk, type and size checked on the stored
 * bytes), download (DocumentDownloadController) and delete. Only records of the user's current team open; identity
 * and solvency categories are listed only to users holding the Alquileres permission.
 */
final class RecordFiles extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    #[Locked]
    public string $owner;

    #[Locked]
    public int $ownerId;

    public function mount(string $owner, int $ownerId): void
    {
        abort_unless(in_array($owner, ['party', 'property'], true), 404);
        $this->owner = $owner;
        $this->ownerId = $ownerId;
        $this->record();   // 404 for another team's record
    }

    public function record(): Model
    {
        $team = (int) auth()->user()?->current_team_id;

        return $this->owner === 'party'
            ? Party::query()->forTeam($team)->findOrFail($this->ownerId)
            : Property::query()->where('team_id', $team)->findOrFail($this->ownerId);
    }

    /** @return array<string, string> categories this user may file */
    public function categoryOptions(): array
    {
        $hidden = RecordDocument::hiddenCategoriesFor(auth()->user());

        return collect(RecordDocument::categories($this->owner))->keys()->reject(fn (string $c): bool => in_array($c, $hidden, true))
            ->mapWithKeys(fn (string $c): array => [$c => __('documentos.categories.'.$c)])->all();
    }

    public function table(Table $table): Table
    {
        $record = $this->record();

        return $table
            ->query(RecordDocument::query()->where('team_id', $record->team_id)->where($this->owner.'_id', $record->getKey())->visibleTo(auth()->user()))
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50])
            ->emptyStateHeading(__('documentos.empty'))
            ->emptyStateIcon('heroicon-o-paper-clip')
            ->columns([
                TextColumn::make('title')->label(__('documentos.fields.title'))->description(fn (RecordDocument $record): ?string => $record->original_name)->searchable()->wrap(),
                TextColumn::make('category')->label(__('documentos.fields.category'))->badge()
                    ->formatStateUsing(fn (string $state): string => __('documentos.categories.'.$state))
                    ->color(fn (string $state): string => RecordDocument::restrictedBy($state) ? 'danger' : 'gray'),
                TextColumn::make('size')->label(__('documentos.fields.size'))->formatStateUsing(fn (int $state): string => Number::fileSize($state, 1)),
                TextColumn::make('created_at')->label(__('documentos.fields.uploaded'))->dateTime('d/m/Y H:i', 'Europe/Madrid')->sortable(),
            ])
            ->headerActions([
                Action::make('upload')->label(__('documentos.upload'))->icon('heroicon-m-arrow-up-tray')
                    ->visible(fn (): bool => \App\Modules\ClartoModules::enabled(\App\Modules\ClartoModules::DOCUMENTOS))
                    ->modalHeading(__('documentos.upload'))
                    ->schema([
                        Select::make('category')->label(__('documentos.fields.category'))->options(fn (): array => $this->categoryOptions())->required()->native(false),
                        TextInput::make('title')->label(__('documentos.fields.title'))->maxLength(200)->helperText(__('documentos.title_help')),
                        FileUpload::make('file')->label(__('documentos.fields.file'))->required()
                            ->disk(RecordDocument::DISK)->directory('documentos/'.$record->team_id)->visibility('private')
                            ->acceptedFileTypes(RecordDocument::MIMES)->maxSize(RecordDocument::MAX_KB)
                            ->storeFileNamesIn('original_name')->helperText(__('documentos.file_help')),
                    ])
                    ->action(fn (array $data) => $this->store($data)),
            ])
            ->recordActions([
                Action::make('download')->label(__('documentos.download'))->icon('heroicon-m-arrow-down-tray')->color('gray')
                    ->url(fn (RecordDocument $record): string => $record->downloadUrl(), shouldOpenInNewTab: true),
                DeleteAction::make()->modalDescription(__('documentos.delete_warning')),
            ]);
    }

    /** @param array{category: string, title?: ?string, file: string, original_name?: ?string} $data */
    public function store(array $data): ?RecordDocument
    {
        $record = $this->record();
        $disk = Storage::disk(RecordDocument::DISK);
        $path = (string) $data['file'];
        $mime = (string) $disk->mimeType($path);
        $size = (int) $disk->size($path);

        if (! array_key_exists($data['category'] ?? '', $this->categoryOptions()) || ! in_array($mime, RecordDocument::MIMES, true) || $size > RecordDocument::MAX_KB * 1024) {
            $disk->delete($path);
            Notification::make()->danger()->title(__('documentos.refused'))->send();

            return null;
        }

        $document = RecordDocument::query()->create([
            'team_id' => $record->team_id,
            $this->owner.'_id' => $record->getKey(),
            'category' => $data['category'],
            'title' => filled($data['title'] ?? null) ? $data['title'] : pathinfo((string) ($data['original_name'] ?? basename($path)), PATHINFO_FILENAME),
            'disk' => RecordDocument::DISK,
            'path' => $path,
            'original_name' => $data['original_name'] ?? null,
            'mime' => $mime,
            'size' => $size,
            'sha256' => hash('sha256', (string) $disk->get($path)),
            'created_by' => auth()->id(),
        ]);
        Notification::make()->success()->title(__('documentos.uploaded'))->send();

        return $document;
    }

    public function render(): View
    {
        return view('filament.documents.record-files');
    }
}
