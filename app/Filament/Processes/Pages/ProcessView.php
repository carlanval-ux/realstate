<?php

declare(strict_types=1);

namespace App\Filament\Processes\Pages;

use App\Filament\Processes\ProcessUi;
use App\Processes\MermaidSvg;
use App\Processes\Models\Process;
use App\Processes\Models\ProcessAttachment;
use App\Processes\Models\ProcessUserState;
use App\Processes\Models\ProcessVersion;
use App\Processes\ProcessAccess;
use App\Processes\ProcessDiff;
use App\Processes\Processes;
use App\Processes\ProcessExtensions;
use App\Processes\ProcessFeedback;
use App\Processes\ProcessLinks;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Url;

/**
 * Procedure page: summary, index, readable steps with personal checklist, decisions, diagram, related processes and
 * «Comunicar una duda o mejora»; management actions (review, publish with diff, archive, history) for whoever may.
 * Direct links answer 404 when the person may not read the process (no title, no existence).
 */
final class ProcessView extends Page
{
    protected static ?string $slug = 'procesos/{process}';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.procesos.view';

    public int $processId;

    #[Url]
    public ?int $v = null;

    public int $versionId;

    public static function getRelativeRouteName(Panel $panel): string
    {
        return 'procesos.ver';
    }

    public static function canAccess(): bool
    {
        $team = ProcessUi::team();

        return $team !== null && ProcessAccess::isStaff(ProcessUi::user(), $team);
    }

    public function mount(int|string $process): void
    {
        $user = ProcessUi::user();
        $model = ProcessUi::process($process);
        $this->processId = $model->getKey();

        if ($this->v !== null) {
            $version = $model->versions()->whereKey($this->v)->first();
            if ($version !== null && ProcessAccess::canView($user, $version)) {
                $this->versionId = $version->getKey();
            } elseif ($version !== null && ProcessAccess::canViewProcess($user, $model)) {
                // A superseded version: whoever only reads goes to the current one, with a notice.
                session()->flash('clp-notice', __('procesos.ver.sustituida'));
                $this->redirect(ProcessLinks::process($model));

                return;
            } else {
                abort(404);
            }
        } else {
            $version = $model->currentVersion;
            if ($version === null || ! ProcessAccess::canView($user, $version)) {
                $version = $model->openVersion;
                abort_if($version === null || ! ProcessAccess::canView($user, $version), 404);
            }
            $this->versionId = $version->getKey();
        }

        if ((string) $model->current_version_id === (string) $this->versionId && $model->status === 'publicado') {
            $state = ProcessUserState::for($user, $model);
            $state->forceFill(['last_viewed_at' => now(), 'last_viewed_version_id' => $this->versionId])->save();
        }
    }

    public function getTitle(): string
    {
        return $this->version()->title;
    }

    public function getSubheading(): ?string
    {
        $p = $this->process();
        $v = $this->version();

        return $p->code.' · '.__('procesos.campos.version').' '.($v->number ?? '—').' · '.__('procesos.estados_version.'.$v->status);
    }

    public function process(): Process
    {
        return Process::query()->with(['area', 'owner', 'approver', 'positions'])->findOrFail($this->processId);
    }

    public function version(): ProcessVersion
    {
        $version = ProcessVersion::query()->whereKey($this->versionId)->where('process_id', $this->processId)->firstOrFail();
        abort_unless(ProcessAccess::canView(ProcessUi::user(), $version), 404);   // re-checked on every request

        return $version;
    }

    public function state(): ProcessUserState
    {
        return ProcessUserState::for(ProcessUi::user(), $this->process());
    }

    public function toggleStep(string $key): void
    {
        $version = $this->version();
        abort_unless(array_key_exists($key, $version->stepNumbers()), 404);
        $state = $this->state();
        $checked = collect((array) $state->checked_steps);
        $state->checked_steps = ($checked->contains($key) ? $checked->reject(fn ($k) => $k === $key) : $checked->push($key))->values()->all();
        $state->save();
    }

    public function resetChecklist(): void
    {
        $this->version();
        $state = $this->state();
        $state->checked_steps = [];
        $state->save();
    }

    public function toggleFavorite(): void
    {
        $this->version();
        $state = $this->state();
        $state->favorite = ! $state->favorite;
        $state->save();
    }

    public function confirmRead(): void
    {
        $version = $this->version();
        abort_unless($version->status === 'publicada', 404);
        $this->state()->forceFill(['read_confirmed_version_id' => $version->getKey(), 'read_confirmed_at' => now()])->save();
        Notification::make()->title(__('procesos.ver.leido_ok', ['n' => $version->number]))->success()->send();
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        $user = ProcessUi::user();
        $process = $this->process();
        $version = $this->version();
        $attachments = $version->attachments()->get()->keyBy('id');
        $related = collect((array) $version->related)->map(function (array $r) use ($process, $user): ?array {
            $target = Process::query()->where('team_id', $process->team_id)->where('code', $r['code'] ?? '')->first();
            $visible = $target !== null && ProcessAccess::canViewProcess($user, $target);

            return $visible ? ['code' => $target->code, 'title' => $target->currentVersion?->title, 'url' => ProcessLinks::process($target), 'relation' => $r['relation'] ?? 'relacionado'] : null;
        })->filter()->values();

        return [
            'p' => $process,
            'v' => $version,
            'state' => $this->state(),
            'sections' => $version->sections(),
            'attachments' => $attachments,
            'diagram' => MermaidSvg::render($version->diagram_mermaid),
            'related' => $related,
            'history' => $version->status === 'publicada' || ProcessAccess::manages($user, $process)
                ? $process->versions()->whereNotNull('number')->orderByDesc('number')->get(['id', 'team_id', 'process_id', 'number', 'status', 'published_at', 'change_summary'])
                : collect(),
            'manages' => ProcessAccess::manages($user, $process),
            'isCurrent' => (string) $process->current_version_id === (string) $version->getKey(),
            'assistant' => ProcessExtensions::assistantUrl(null, $version),
            'readers' => ProcessAccess::manages($user, $process) ? ProcessUserState::query()->where('read_confirmed_version_id', $version->getKey())->count() : null,
        ];
    }

    public function attachmentUrl(?int $id): ?string
    {
        $attachment = $id ? ProcessAttachment::query()->where('process_version_id', $this->versionId)->find($id) : null;

        return $attachment ? ProcessLinks::attachment($attachment) : null;
    }

    public function md(?string $markdown): HtmlString
    {
        return ProcessUi::md($markdown);
    }

    public function diagram(?string $code): ?string
    {
        return MermaidSvg::render($code);
    }

    /** @return array{0: ProcessVersion|null, 1: list<array>} the version this one is compared with, and the changes */
    public function diff(): array
    {
        $version = $this->version();
        $process = $this->process();
        $base = $version->based_on_version_id ? ProcessVersion::query()->find($version->based_on_version_id) : null;
        $base ??= $process->currentVersion && $process->current_version_id !== $version->getKey() ? $process->currentVersion : null;

        return [$base, ProcessDiff::between($base, $version)];
    }

    protected function getHeaderActions(): array
    {
        $user = ProcessUi::user();
        $on = ProcessUi::moduleOn();

        return [
            Action::make('duda')->label(__('procesos.ver.duda'))->icon('heroicon-o-chat-bubble-left-ellipsis')->color('gray')
                ->modalDescription(__('procesos.ver.duda_ayuda'))
                ->schema([
                    Select::make('step')->label(__('procesos.ver.duda_paso'))->placeholder(__('procesos.ver.duda_general'))
                        ->options(fn (): array => collect((array) $this->version()->steps)->values()->mapWithKeys(fn (array $s, int $i): array => [$s['key'] => __('procesos.ver.paso_n', ['n' => $i + 1]).'. '.($s['title'] ?? '')])->all()),
                    Textarea::make('text')->label(__('procesos.campos.text'))->required()->rows(5)->maxLength(5000),
                ])
                ->action(function (array $data) use ($user): void {
                    $request = ProcessFeedback::submit($user, $this->process()->team_id, $data['text'], $this->version(), $data['step'] ?? null);
                    Notification::make()->title(__('procesos.ver.duda_ok'))->body(__('procesos.ver.duda_ok_cuerpo', ['id' => $request->getKey()]))->success()->send();
                }),
            Action::make('editar')->label(fn (): string => $this->process()->openVersion ? __('procesos.ver.continuar_borrador') : __('procesos.ver.nueva_version'))->icon('heroicon-o-pencil-square')
                ->visible(fn (): bool => $on && $this->process()->status !== 'archivado' && ProcessAccess::canEdit($user, $this->process()) && $this->process()->openVersion?->status !== 'en_revision')
                ->action(function () use ($user): void {
                    Processes::startRevision($user, $this->process());
                    $this->redirect(ProcessEditor::getUrl(['process' => $this->processId]));
                }),
            Action::make('enviar')->label(__('procesos.ver.enviar'))->icon('heroicon-o-paper-airplane')
                ->visible(fn (): bool => $on && $this->version()->status === 'borrador' && ProcessAccess::canEdit($user, $this->process()))
                ->requiresConfirmation()->modalDescription(fn (): string => ($gaps = $this->version()->publicationGaps()) ? implode("\n", $gaps) : __('procesos.ver.enviar_ayuda'))
                ->action(function () use ($user): void {
                    Processes::submit($user, $this->version());
                    Notification::make()->title(__('procesos.ver.enviado'))->success()->send();
                }),
            Action::make('revision')->label(__('procesos.ver.revisar'))->icon('heroicon-o-check-badge')->color('success')
                ->visible(fn (): bool => $on && (ProcessAccess::canReview($user, $this->version()) || ProcessAccess::canPublish($user, $this->version())))
                ->modalWidth('5xl')->modalHeading(__('procesos.ver.revisar'))
                ->modalContent(fn (): \Illuminate\Contracts\View\View => view('filament.procesos.diff', ['diff' => $this->diff(), 'gaps' => $this->version()->publicationGaps(), 'warnings' => $this->version()->publicationWarnings()]))
                ->schema([
                    Select::make('decision')->label(__('procesos.ver.decision'))->required()->live()
                        ->options(fn (): array => array_filter([
                            'publicar' => ProcessAccess::canPublish($user, $this->version()) ? __('procesos.ver.publicar') : null,
                            'visto_bueno' => (string) $this->version()->created_by !== (string) $user->getKey() && ProcessAccess::canReview($user, $this->version()) ? __('procesos.ver.visto_bueno') : null,
                            'devolver' => ProcessAccess::canReview($user, $this->version()) ? __('procesos.ver.devolver') : null,
                        ])),
                    DatePicker::make('next_review_on')->label(__('procesos.campos.next_review_on'))->minDate(now()->addDay())
                        ->default(fn () => $this->process()->next_review_on && $this->process()->next_review_on->isFuture() ? $this->process()->next_review_on : now()->addMonths(\App\Processes\Models\ProcessSettings::forTeam($this->process()->team_id)->default_review_months))
                        ->visible(fn ($get): bool => $get('decision') === 'publicar'),
                    Textarea::make('notes')->label(__('procesos.campos.notes'))->rows(4)->required(fn ($get): bool => $get('decision') === 'devolver'),
                ])
                ->action(function (array $data) use ($user): void {
                    match ($data['decision']) {
                        'publicar' => Processes::publish($user, $this->version(), filled($data['next_review_on'] ?? null) ? new \DateTimeImmutable($data['next_review_on']) : null),
                        'visto_bueno' => Processes::markReviewed($user, $this->version(), $data['notes'] ?? null),
                        default => Processes::sendBack($user, $this->version(), (string) ($data['notes'] ?? '')),
                    };
                    Notification::make()->title(__('procesos.ver.hecho'))->success()->send();
                    $this->redirect(ProcessLinks::process($this->process()));
                }),
            ActionGroup::make([
                Action::make('retirar')->label(__('procesos.ver.retirar'))->icon('heroicon-o-arrow-uturn-left')
                    ->visible(fn (): bool => $this->version()->status === 'en_revision' && ((string) $this->version()->submitted_by === (string) $user->getKey() || ProcessAccess::canMaintain($user, $this->process())))
                    ->requiresConfirmation()->action(fn () => Processes::withdraw($user, $this->version())),
                Action::make('vigente')->label(__('procesos.ver.confirmar_vigencia'))->icon('heroicon-o-calendar-days')
                    ->visible(fn (): bool => $on && $this->process()->status === 'publicado' && ProcessAccess::canMaintain($user, $this->process()))
                    ->schema([DatePicker::make('date')->label(__('procesos.campos.next_review_on'))->required()->minDate(now()->addDay())->default(now()->addYear())])
                    ->action(function (array $data) use ($user): void {
                        Processes::confirmStillValid($user, $this->process(), new \DateTimeImmutable($data['date']));
                        Notification::make()->title(__('procesos.ver.hecho'))->success()->send();
                    }),
                Action::make('archivar')->label(__('procesos.ver.archivar'))->icon('heroicon-o-archive-box')->color('danger')
                    ->visible(fn (): bool => $on && $this->process()->status === 'publicado' && ProcessAccess::canMaintain($user, $this->process()))
                    ->modalDescription(__('procesos.ver.archivar_ayuda'))
                    ->schema([Textarea::make('reason')->label(__('procesos.campos.reason'))->required()->rows(3)])
                    ->action(function (array $data) use ($user): void {
                        Processes::archive($user, $this->process(), $data['reason']);
                        Notification::make()->title(__('procesos.ver.archivado'))->success()->send();
                        $this->redirect(ProcessLinks::process($this->process()));
                    }),
                Action::make('restaurar')->label(__('procesos.ver.restaurar'))->icon('heroicon-o-arrow-path')
                    ->visible(fn (): bool => $on && $this->process()->status === 'archivado' && ProcessAccess::canEdit($user, $this->process()))
                    ->requiresConfirmation()->modalDescription(__('procesos.ver.restaurar_ayuda'))
                    ->action(function () use ($user): void {
                        Processes::startRevision($user, $this->process());
                        $this->redirect(ProcessEditor::getUrl(['process' => $this->processId]));
                    }),
                Action::make('comparar')->label(__('procesos.ver.comparar'))->icon('heroicon-o-arrows-right-left')
                    ->visible(fn (): bool => ProcessAccess::manages($user, $this->process()) || $this->version()->isOpen())
                    ->modalWidth('5xl')->modalSubmitAction(false)->modalCancelActionLabel(__('procesos.ver.cerrar'))
                    ->modalContent(fn (): \Illuminate\Contracts\View\View => view('filament.procesos.diff', ['diff' => $this->diff(), 'gaps' => $this->version()->isOpen() ? $this->version()->publicationGaps() : [], 'warnings' => []])),
                Action::make('historial')->label(__('procesos.ver.historial'))->icon('heroicon-o-clock')
                    ->visible(fn (): bool => ProcessAccess::manages($user, $this->process()) || ProcessAccess::canManage($user, $this->process()->team_id))
                    ->modalWidth('4xl')->modalSubmitAction(false)->modalCancelActionLabel(__('procesos.ver.cerrar'))
                    ->modalContent(fn (): \Illuminate\Contracts\View\View => view('filament.procesos.history', ['events' => $this->process()->events()->with('user')->limit(200)->get(), 'versions' => $this->process()->versions()->get()])),
                Action::make('eliminar')->label(__('procesos.ver.eliminar_borrador'))->icon('heroicon-o-trash')->color('danger')
                    ->visible(fn (): bool => $this->version()->status === 'borrador' && $this->version()->number === null
                        && ((string) $this->version()->created_by === (string) $user->getKey() || ProcessAccess::isAdmin($user, $this->process()->team_id)))
                    ->requiresConfirmation()
                    ->action(function () use ($user): void {
                        $remaining = $this->process()->versions()->count() > 1;
                        Processes::deleteDraft($user, $this->version());
                        $this->redirect($remaining ? ProcessLinks::process($this->process()) : ProcessHome::getUrl());
                    }),
            ])->label(__('procesos.ver.mas'))->icon('heroicon-m-ellipsis-vertical')->color('gray')->button(),
        ];
    }

    /** Text of the TARMIO kind, or null. */
    public function tarmio(?string $kind): ?string
    {
        return filled($kind) ? __('procesos.tarmio.'.$kind) : null;
    }

    /** @return Collection<int, array{key: string, number: int}> */
    public function stepIndex(): Collection
    {
        return collect($this->version()->stepNumbers())->map(fn (int $n, string $k): array => ['key' => $k, 'number' => $n])->values();
    }
}
