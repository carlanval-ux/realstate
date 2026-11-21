<?php

declare(strict_types=1);

namespace App\Filament\Processes\Pages;

use App\Filament\Processes\ProcessUi;
use App\Filament\Processes\Resources\AreaResource;
use App\Models\ImprovementRequest;
use App\Processes\Models\Process;
use App\Processes\Models\ProcessFeedbackLink;
use App\Processes\Models\ProcessMapEntry;
use App\Processes\Models\ProcessSettings;
use App\Processes\Models\ProcessUserState;
use App\Processes\Models\ProcessVersion;
use App\Processes\ProcessAccess;
use App\Processes\ProcessExtensions;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

/**
 * «Gestión de procesos»: pending review, reviews due, owners missing, doubts received, all processes with the
 * anonymous count of read confirmations, the process map and the tabs the assistant adds (recommendations, queries).
 * Administrators see the whole company; reviewers the review queue; an owner, what concerns their own processes.
 */
final class ProcessAdmin extends Page
{
    protected static ?string $slug = 'gestion-procesos';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return ProcessUi::group();
    }

    public static function getNavigationLabel(): string
    {
        return __('procesos.gestion.menu');
    }

    public function getTitle(): string
    {
        return __('procesos.gestion.menu');
    }

    public static function canAccess(): bool
    {
        $team = ProcessUi::team();
        $user = ProcessUi::user();

        return $team !== null && $user !== null && (ProcessAccess::canManage($user, $team)
            || (ProcessAccess::isStaff($user, $team) && Process::query()->where('team_id', $team)->where('owner_user_id', $user->getKey())->exists()));
    }

    public function content(Schema $schema): Schema
    {
        $team = ProcessUi::team();
        $user = ProcessUi::user();
        $admin = ProcessAccess::canAdminister($user, $team);
        // Everything is filtered by the same rules as the procedure page: management pass of ProcessAccess.
        $manages = fn (?Process $p): bool => $p !== null && ProcessAccess::manages($user, $p);
        $tab = fn (string $key, string $label, array $rows, ?int $badge = null): Tab => Tab::make($label)->id($key)->badge($badge)
            ->schema([View::make('filament.procesos.admin-tab')->viewData(['tab' => $key, 'rows' => $rows, 'page' => $this])]);

        $pending = ProcessVersion::query()->where('team_id', $team)->whereIn('status', ProcessVersion::OPEN)->with(['process', 'author'])
            ->orderByRaw("status = 'en_revision' desc")->orderBy('updated_at')->get()->filter(fn (ProcessVersion $v): bool => ProcessAccess::canView($user, $v))->values()->all();
        $due = Process::query()->where('team_id', $team)->where('status', 'publicado')->whereDate('next_review_on', '<=', now()->addDays(30))
            ->with(['owner', 'currentVersion'])->orderBy('next_review_on')->get()->filter($manages)->values()->all();
        $orphans = $admin ? Process::query()->where('team_id', $team)->where('status', '!=', 'archivado')->with(['owner', 'currentVersion', 'openVersion'])->get()
            ->filter(fn (Process $p): bool => $p->ownerMissing())->values()->all() : [];
        $doubts = ProcessFeedbackLink::query()->where('team_id', $team)->with(['improvementRequest.user', 'process'])->orderByDesc('id')->limit(300)->get()
            ->filter(fn (ProcessFeedbackLink $f): bool => $admin || $manages($f->process))->values()->all();
        $processes = Process::query()->where('team_id', $team)->with(['owner', 'currentVersion', 'openVersion', 'area'])->orderBy('code')->get()->filter($manages)->values();
        $reads = ProcessUserState::query()->whereIn('read_confirmed_version_id', $processes->pluck('current_version_id')->filter())
            ->selectRaw('read_confirmed_version_id, count(*) as n')->groupBy('read_confirmed_version_id')->pluck('n', 'read_confirmed_version_id');
        $map = $admin
            ? ProcessMapEntry::query()->where('team_id', $team)->with('process')->orderBy('priority')->orderBy('key')->get()->all() : [];

        $tabs = [
            $tab('revision', __('procesos.gestion.pendientes'), $pending, count(array_filter($pending, fn ($v) => $v->status === 'en_revision')) ?: null),
            $tab('vencimientos', __('procesos.gestion.vencimientos'), $due, count($due) ?: null),
            $tab('responsables', __('procesos.gestion.sin_responsable'), $orphans, count($orphans) ?: null),
            $tab('dudas', __('procesos.gestion.dudas'), $doubts, count(array_filter($doubts, fn ($d) => in_array($d->improvementRequest?->status, ['received', 'in_review'], true))) ?: null),
            $tab('procesos', __('procesos.gestion.todos'), ['processes' => $processes->all(), 'reads' => $reads->all()]),
        ];
        if ($map !== []) {
            $tabs[] = $tab('mapa', __('procesos.gestion.mapa'), $map);
        }
        if ($admin) {
            array_push($tabs, ...array_values(ProcessExtensions::adminTabs()));
        }

        return $schema->components([Tabs::make('gestion')->persistTabInQueryString()->tabs($tabs)]);
    }

    public function respondAction(): Action
    {
        return Action::make('respond')->label(__('procesos.gestion.responder'))->link()
            ->schema([
                Select::make('status')->label(__('procesos.campos.status'))->required()->options(ImprovementRequest::statusOptions())
                    ->default(fn (array $arguments): ?string => ImprovementRequest::query()->find($arguments['request'] ?? 0)?->status),
                Textarea::make('reply')->label(__('procesos.gestion.respuesta'))->rows(4),
            ])
            ->action(function (array $data, array $arguments): void {
                $request = ImprovementRequest::query()->where('team_id', ProcessUi::team())->findOrFail($arguments['request'] ?? 0);
                $request->respond(ProcessUi::user(), $data['status'], $data['reply'] ?? null);
                Notification::make()->title(__('procesos.ver.hecho'))->success()->send();
            });
    }

    protected function getHeaderActions(): array
    {
        $team = ProcessUi::team();

        return [
            Action::make('ajustes')->label(__('procesos.gestion.ajustes'))->icon('heroicon-o-cog-6-tooth')->color('gray')
                ->visible(fn (): bool => ProcessAccess::canAdminister(ProcessUi::user(), $team))
                ->fillForm(fn (): array => ProcessSettings::forTeam($team)->only(['default_review_months', 'require_four_eyes', 'review_tasks_in_tarmio']))
                ->schema([
                    TextInput::make('default_review_months')->label(__('procesos.campos.default_review_months'))->numeric()->minValue(1)->maxValue(60)->required(),
                    Toggle::make('require_four_eyes')->label(__('procesos.campos.require_four_eyes')),
                    Toggle::make('review_tasks_in_tarmio')->label(__('procesos.campos.review_tasks_in_tarmio')),
                ])
                ->action(function (array $data) use ($team): void {
                    abort_unless(ProcessAccess::canAdminister(ProcessUi::user(), $team), 403);
                    ProcessSettings::forTeam($team)->fill(array_intersect_key($data, array_flip(['default_review_months', 'require_four_eyes', 'review_tasks_in_tarmio'])))->save();
                    Notification::make()->title(__('procesos.editor.guardado'))->success()->send();
                }),
            Action::make('estructura')->label(__('procesos.estructura.menu'))->icon('heroicon-o-user-group')->color('gray')
                ->visible(fn (): bool => ProcessAccess::canAdminister(ProcessUi::user(), $team))
                ->url(fn (): string => AreaResource::getUrl()),
            Action::make('portada')->label(__('procesos.inicio.menu'))->icon('heroicon-o-book-open')->color('gray')->url(fn (): string => ProcessHome::getUrl()),
        ];
    }
}
