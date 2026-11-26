<?php

declare(strict_types=1);

namespace App\Filament\Resources\CompanyDocumentResource\Pages;

use App\Documents\CompanyDocument;
use App\Documents\CompanyDocumentFolder;
use App\Filament\Resources\CompanyDocumentFolderResource;
use App\Filament\Resources\CompanyDocumentResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Url;

/**
 * The list, in two tabs: «Carpetas» (default, the everyday one: a folder browser — path, subfolders, then that folder's
 * expedientes; searching or filtering looks in the folder and every subfolder) and «Aprobaciones» (every expediente
 * that asked for approval, with its count). The open folder is ?carpeta=ID, so it can be linked and «atrás» goes up.
 */
final class ManageCompanyDocuments extends ManageRecords
{
    public const TAB_FOLDERS = 'carpetas';

    public const TAB_APPROVALS = 'aprobaciones';

    protected static string $resource = CompanyDocumentResource::class;

    /**
     * The folder open in «Carpetas» (null = Inicio). A string: it comes from the query string. Folders are opened with
     * plain links (a new page each), so the address, «atrás» and «abrir en otra pestaña» always work; Livewire 4's
     * history: true lost track of the URL after the first update.
     */
    #[Url(as: 'carpeta')]
    public ?string $folderId = null;

    public function mount(): void
    {
        parent::mount();
        CompanyDocumentFolder::ensureDefaults($this->teamId());   // first visit of the company: starting folders
        if ($this->currentFolder() === null) {
            $this->folderId = null;   // unknown, or another company's: Inicio
        }
    }

    public function getTabs(): array
    {
        $pending = CompanyDocument::query()->where('team_id', $this->teamId())->where('status', CompanyDocument::PENDING)->count();

        return [
            self::TAB_FOLDERS => Tab::make(__('documentacion.tabs.folders'))->icon('heroicon-o-folder')
                ->modifyQueryUsing(fn (Builder $query): Builder => $this->scopeToFolder($query))
                ->excludeQueryWhenResolvingRecord(),
            // Everything that asked for approval (pending, approved, with conditions, signed, rejected); not «Disponible».
            self::TAB_APPROVALS => Tab::make(__('documentacion.tabs.approvals'))->icon('heroicon-o-check-badge')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', '!=', CompanyDocument::AVAILABLE))
                ->badge($pending > 0 ? $pending : null)->badgeColor('warning'),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getTabsContentComponent(),
            Html::make(fn (): HtmlString => $this->folderBrowser())->visible(fn (): bool => $this->inFolders()),
            RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
            EmbeddedTable::make(),
            RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
        ]);
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->emptyStateHeading(fn (): ?string => $this->emptyState()[0])
            ->emptyStateDescription(fn (): ?string => $this->emptyState()[1]);
    }

    /** The open folder, only if it belongs to this company. */
    public function currentFolder(): ?CompanyDocumentFolder
    {
        $id = (string) $this->folderId;

        return ctype_digit($id) ? CompanyDocumentFolder::query()->where('team_id', $this->teamId())->find((int) $id) : null;
    }

    /** The folder «Nuevo expediente» starts with: the open one, when browsing folders. */
    public function folderForNewDocument(): ?int
    {
        return $this->inFolders() ? $this->currentFolder()?->getKey() : null;
    }

    /**
     * Browsing: only the open folder's own expedientes (at Inicio, those without a folder: none normally). Searching or
     * filtering: the open folder and every folder below it (at Inicio, the whole archive).
     *
     * @param  Builder<CompanyDocument>  $query
     * @return Builder<CompanyDocument>
     */
    public function scopeToFolder(Builder $query): Builder
    {
        $folder = $this->currentFolder();
        if ($this->looksDeep()) {
            return $folder === null ? $query : $query->whereIn('folder_id', $folder->withDescendantIds());
        }

        return $folder === null ? $query->whereNull('folder_id') : $query->where('folder_id', $folder->getKey());
    }

    /** A search or any filter is on: the browser looks in every subfolder. */
    public function looksDeep(): bool
    {
        return filled($this->getTableSearch())
            || collect($this->tableFilters ?? [])->flatten()->contains(fn (mixed $value): bool => filled($value) && $value !== false);
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('documentacion.new'))->icon('heroicon-o-plus')->createAnother(false)->modalWidth('3xl')
                ->using(function (array $data): CompanyDocument {
                    $user = auth()->user();
                    abort_unless($user instanceof User, 403);

                    return CompanyDocument::saveFor($user, $this->teamId() ?? $user->current_team_id, CompanyDocumentResource::fromForm($data));
                }),
            Action::make('newFolder')->label(__('documentacion.browser.new_folder'))->icon('heroicon-o-folder-plus')->color('gray')
                ->visible(fn (): bool => $this->inFolders())
                ->modalHeading(fn (): string => ($folder = $this->currentFolder()) === null ? __('documentacion.browser.new_folder')
                    : __('documentacion.browser.new_folder_in', ['folder' => $folder->name]))
                ->schema([TextInput::make('name')->label(__('documentacion.folders.name'))->required()->maxLength(120)])
                ->action(function (array $data): void {
                    CompanyDocumentFolder::createFor(auth()->user(), $this->teamId(), (string) $data['name'], $this->currentFolder()?->getKey());
                    Notification::make()->success()->title(__('documentacion.browser.folder_created'))->send();
                }),
            Action::make('renameFolder')->label(__('documentacion.browser.rename_folder'))->icon('heroicon-o-pencil-square')->color('gray')
                ->visible(fn (): bool => $this->inFolders() && $this->currentFolder() !== null)
                ->modalHeading(fn (): string => __('documentacion.browser.rename_heading', ['folder' => (string) $this->currentFolder()?->name]))
                ->fillForm(fn (): array => ['name' => $this->currentFolder()?->name])
                ->schema([TextInput::make('name')->label(__('documentacion.folders.name'))->required()->maxLength(120)])
                ->action(function (array $data): void {
                    $this->currentFolder()?->rename(auth()->user(), (string) $data['name']);
                    Notification::make()->success()->title(__('documentacion.browser.folder_renamed'))->send();
                }),
            // Moving and deleting folders stay in the folders list.
            Action::make('folders')->label(__('documentacion.browser.manage'))->icon('heroicon-o-folder')->color('gray')
                ->url(fn (): string => CompanyDocumentFolderResource::getUrl()),
        ];
    }

    private function inFolders(): bool
    {
        return ($this->activeTab ?? self::TAB_FOLDERS) === self::TAB_FOLDERS;
    }

    private function teamId(): int|string|null
    {
        return Filament::getTenant()?->getKey();
    }

    /** The URL of a folder in the browser (null = Inicio): what the links point to, for opening them in a new tab. */
    private function folderUrl(?int $folderId): string
    {
        return CompanyDocumentResource::getUrl('index', array_filter(['tab' => self::TAB_FOLDERS, 'carpeta' => $folderId]));
    }

    /** A link that opens a folder (the search is left behind, like opening a folder in a file browser). */
    private function folderLink(?int $folderId, string $inner, string $style = ''): string
    {
        return '<a href="'.e($this->folderUrl($folderId)).'"'.($style !== '' ? ' style="'.$style.'"' : '').'>'.$inner.'</a>';
    }

    /** The path (Inicio › Contratos › Colaboración, each step a link but the last) and the subfolders as cards. */
    private function folderBrowser(): HtmlString
    {
        $current = $this->currentFolder();
        $steps = [[null, __('documentacion.browser.home')], ...array_map(fn (CompanyDocumentFolder $f): array => [(int) $f->getKey(), $f->name], $current?->trail() ?? [])];
        $last = count($steps) - 1;
        $path = [];
        foreach ($steps as $i => [$id, $name]) {
            $path[] = $i === $last ? '<span aria-current="page" style="font-weight:600">'.e($name).'</span>'
                : $this->folderLink($id, e($name), 'text-decoration:underline;opacity:.85');
        }
        $html = '<nav aria-label="'.e(__('documentacion.browser.trail')).'" style="display:flex;flex-wrap:wrap;align-items:center;gap:.4rem;font-size:1rem;margin-bottom:.75rem">'
            .implode('<span aria-hidden="true" style="opacity:.5">›</span>', $path).'</nav>';

        $deep = $this->looksDeep();
        if ($deep) {
            $html .= '<p style="font-size:.875rem;opacity:.75;margin-bottom:.75rem">'
                .e(__('documentacion.browser.searching', ['folder' => implode(' › ', array_column($steps, 1))])).'</p>';
        }

        $cards = '';
        foreach ($this->visibleSubfolders($current, $deep) as $folder) {
            [$subfolders, $documents] = [(int) $folder->getAttribute('children_count'), (int) $folder->getAttribute('documents_count')];
            $counts = $subfolders + $documents === 0 ? __('documentacion.browser.empty') : implode(' · ', array_filter([
                $subfolders > 0 ? trans_choice('documentacion.browser.folders_count', $subfolders, ['count' => $subfolders]) : null,
                $documents > 0 ? trans_choice('documentacion.browser.documents_count', $documents, ['count' => $documents]) : null,
            ]));
            $cards .= $this->folderLink((int) $folder->getKey(),
                svg('heroicon-s-folder', '', ['style' => 'width:1.75rem;height:1.75rem;flex-shrink:0;color:rgb(245,158,11)', 'aria-hidden' => 'true'])->toHtml()
                .'<span style="min-width:0"><span style="display:block;font-weight:500;overflow-wrap:anywhere">'.e($deep ? $folder->path() : $folder->name).'</span>'
                .'<span style="display:block;font-size:.8rem;opacity:.65">'.e($counts).'</span></span>',
                'display:flex;align-items:center;gap:.65rem;padding:.7rem .9rem;border:1px solid rgba(127,127,127,.3);border-radius:.75rem');
        }
        if ($cards !== '') {
            $html .= '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(15rem,1fr));gap:.75rem;margin-bottom:1rem">'.$cards.'</div>';
        }

        return new HtmlString($html);
    }

    /**
     * Browsing: the open folder's subfolders. Searching: the folders below it whose name has the words searched
     * (filters alone show no folders). By name, naturally sorted, with their own counts.
     *
     * @return list<CompanyDocumentFolder>
     */
    private function visibleSubfolders(?CompanyDocumentFolder $current, bool $deep): array
    {
        $query = CompanyDocumentFolder::query()->where('team_id', $this->teamId())->withCount(['children', 'documents']);
        if (! $deep) {
            $query->where('parent_id', $current?->getKey());
        } elseif (filled($search = trim((string) $this->getTableSearch()))) {
            $query->where('name', 'like', '%'.$search.'%');
            if ($current !== null) {
                $query->whereIn('id', array_diff($current->withDescendantIds(), [(int) $current->getKey()]));
            }
        } else {
            return [];
        }

        return $query->get()->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
    }

    /** @return array{0: string|null, 1: string|null} heading and description when the table has no rows */
    private function emptyState(): array
    {
        $key = match (true) {
            ! $this->inFolders() => 'approvals_empty',
            $this->looksDeep() => 'search_empty',
            $this->currentFolder() === null => 'root_empty',
            default => 'folder_empty',
        };

        return [__("documentacion.browser.{$key}"), __("documentacion.browser.{$key}_help")];
    }
}
