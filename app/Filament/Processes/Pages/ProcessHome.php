<?php

declare(strict_types=1);

namespace App\Filament\Processes\Pages;

use App\Filament\Processes\ProcessUi;
use App\Processes\Models\Area;
use App\Processes\Models\Position;
use App\Processes\Models\ProcessMapEntry;
use App\Processes\Models\ProcessUserState;
use App\Processes\Models\ProcessVersion;
use App\Processes\ProcessAccess;
use App\Processes\Processes;
use App\Processes\ProcessExtensions;
use App\Processes\ProcessTemplates;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/**
 * «Procesos»: home with the search box, «Procesos de mi puesto», favourites, recently viewed and browsing by area,
 * position and task. Everything listed comes from ProcessAccess::visibleQuery (current published versions the person
 * may read); the search filters that query, never the other way round.
 */
final class ProcessHome extends Page
{
    protected static ?string $slug = 'procesos';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.procesos.home';

    #[Url]
    public string $q = '';

    #[Url]
    public ?int $area = null;

    #[Url]
    public ?int $puesto = null;

    #[Url]
    public ?string $tarea = null;

    public static function getNavigationGroup(): ?string
    {
        return ProcessUi::group();
    }

    public static function getNavigationLabel(): string
    {
        return __('procesos.inicio.menu');
    }

    public function getTitle(): string
    {
        return __('procesos.inicio.titulo');
    }

    public static function canAccess(): bool
    {
        $team = ProcessUi::team();

        return $team !== null && ProcessAccess::isStaff(ProcessUi::user(), $team);
    }

    public function clearFilters(): void
    {
        $this->reset(['q', 'area', 'puesto', 'tarea']);
    }

    public function toggleFavorite(int $processId): void
    {
        $version = ProcessAccess::visibleQuery(ProcessUi::user(), ProcessUi::team())->where('clarto_processes.id', $processId)->first();
        abort_if($version === null, 404);
        $state = ProcessUserState::for(ProcessUi::user(), $version->process);
        $state->favorite = ! $state->favorite;
        $state->save();
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        $user = ProcessUi::user();
        $team = ProcessUi::team();
        $visible = ProcessAccess::visibleQuery($user, $team)->with(['process.owner', 'process.area', 'process.positions'])->get()
            ->sortBy(fn (ProcessVersion $v): string => $v->process->code)->keyBy('process_id');
        $states = ProcessUserState::query()->where('user_id', $user->getKey())->whereIn('process_id', $visible->keys())->get()->keyBy('process_id');
        $myPositions = ProcessAccess::positionIds($user, $team);

        $results = null;
        if (filled(trim($this->q)) || $this->area || $this->puesto || filled($this->tarea)) {
            $query = ProcessAccess::visibleQuery($user, $team);
            foreach (array_filter(preg_split('/\s+/u', ProcessVersion::normalize(trim($this->q.' '.$this->tarea))) ?: [], fn (string $t): bool => mb_strlen($t) >= 2) as $term) {
                $query->where('clarto_process_versions.search_text', 'like', '%'.$term.'%');
            }
            if ($this->area) {
                $query->where('clarto_processes.area_id', $this->area);
            }
            if ($this->puesto) {
                $query->whereExists(fn ($s) => $s->selectRaw('1')->from('clarto_process_positions')->whereColumn('clarto_process_positions.process_id', 'clarto_processes.id')->where('position_id', $this->puesto));
            }
            $ids = $query->limit(100)->pluck('clarto_process_versions.process_id');
            $needle = ProcessVersion::normalize($this->q);
            $results = $visible->only($ids->all())->sortBy(fn (ProcessVersion $v): string => (filled($needle) && str_contains(ProcessVersion::normalize($v->title), $needle) ? '0' : '1').$v->title)->values();
        }

        $keywords = $visible->flatMap(fn (ProcessVersion $v): array => array_map('mb_strtolower', (array) $v->keywords))->countBy()->sortDesc()->take(24);

        return [
            'results' => $results,
            'mine' => $visible->filter(fn (ProcessVersion $v): bool => $v->process->positions->pluck('id')->intersect($myPositions)->isNotEmpty())->values(),
            'hasPosition' => $myPositions !== [],
            'favorites' => $visible->only($states->where('favorite', true)->keys()->all())->values(),
            'recent' => $states->filter(fn (ProcessUserState $s): bool => $s->last_viewed_at !== null)->sortByDesc('last_viewed_at')->take(6)
                ->map(fn (ProcessUserState $s) => $visible->get($s->process_id))->filter()->values(),
            'states' => $states,
            'areas' => Area::query()->where('team_id', $team)->where('active', true)->orderBy('sort')->get()
                ->map(fn (Area $a): array => ['id' => $a->id, 'label' => $a->label(), 'count' => $visible->where('process.area_id', $a->id)->count()]),
            'positions' => Position::query()->where('team_id', $team)->where('active', true)->orderBy('sort')->orderBy('name')->get()
                ->map(fn (Position $p): array => ['id' => $p->id, 'label' => $p->label(), 'count' => $visible->filter(fn ($v) => $v->process->positions->contains('id', $p->id))->count()])
                ->where('count', '>', 0)->values(),
            'keywords' => $keywords->keys(),
            'total' => $visible->count(),
            'assistant' => ProcessExtensions::assistantUrl(filled($this->q) ? $this->q : null),
        ];
    }

    protected function getHeaderActions(): array
    {
        $team = ProcessUi::team();

        return [
            Action::make('nuevo')->label(__('procesos.inicio.nuevo'))->icon('heroicon-o-plus')
                ->visible(fn (): bool => ProcessUi::moduleOn() && ProcessAccess::canCreate(ProcessUi::user(), $team))
                ->modalHeading(__('procesos.inicio.nuevo'))->modalDescription(__('procesos.inicio.nuevo_ayuda'))
                ->schema([
                    TextInput::make('title')->label(__('procesos.campos.title'))->required()->maxLength(160),
                    Select::make('map_entry_id')->label(__('procesos.campos.map_entry'))->searchable()
                        ->options(fn (): array => ProcessMapEntry::query()->where('team_id', $team)->whereNull('process_id')->where('status', '!=', 'descartado')->orderBy('key')->get()
                            ->mapWithKeys(fn (ProcessMapEntry $e): array => [$e->id => $e->key.' · '.$e->title])->all())
                        ->helperText(__('procesos.inicio.mapa_ayuda')),
                    Select::make('area_id')->label(__('procesos.campos.area'))->requiredWithout('map_entry_id')
                        ->options(fn (): array => Area::query()->where('team_id', $team)->where('active', true)->orderBy('sort')->get()->mapWithKeys(fn (Area $a): array => [$a->id => $a->label()])->all()),
                    Select::make('template_key')->label(__('procesos.campos.template'))->options(ProcessTemplates::options())->default('instruccion_breve')
                        ->helperText(__('procesos.inicio.plantilla_ayuda')),
                ])
                ->action(function (array $data) use ($team): void {
                    $version = Processes::createDraft(ProcessUi::user(), $team, array_filter([
                        'title' => $data['title'], 'area_id' => $data['area_id'] ?? null, 'map_entry_id' => $data['map_entry_id'] ?? null,
                    ]), filled($data['map_entry_id'] ?? null) ? null : ($data['template_key'] ?? null));
                    $this->redirect(ProcessEditor::getUrl(['process' => $version->process_id]));
                }),
            Action::make('gestion')->label(__('procesos.gestion.menu'))->icon('heroicon-o-clipboard-document-check')->color('gray')
                ->visible(fn (): bool => ProcessAccess::canManage(ProcessUi::user(), $team))
                ->url(fn (): string => ProcessAdmin::getUrl()),
        ];
    }
}
