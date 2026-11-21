<?php

declare(strict_types=1);

namespace App\Filament\Processes\Assistant;

use App\Models\User;
use App\Processes\Assistant\Assistant;
use App\Processes\Assistant\AssistantQuery;
use App\Processes\Assistant\AssistantSettings;
use App\Processes\ProcessAccess;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Throwable;

/**
 * «Preguntar a CLARTO»: question box, brief answer with links to the procedure version, clarification options,
 * «Enviar la duda al responsable», rating and the person's own recent questions. ?q= prefills (and asks), ?v= is the
 * procedure it was asked from. Everything shown is re-checked against the person's access when rendered.
 */
final class AskClarto extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $slug = 'preguntar-a-clarto';

    protected static ?int $navigationSort = 40;

    protected string $view = 'filament.processes.assistant.ask';

    public string $question = '';

    public ?int $context = null;

    public ?int $queryId = null;

    public string $comment = '';

    public string $note = '';

    public static function getNavigationLabel(): string
    {
        return __('asistente.titulo');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('asistente.nav_grupo');
    }

    public function getTitle(): string
    {
        return __('asistente.titulo');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();
        $team = Filament::getTenant()?->getKey();

        return $user instanceof User && $team !== null && ProcessAccess::isStaff($user, $team);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canAccess() && AssistantSettings::activeFor(Filament::getTenant()?->getKey());
    }

    public function mount(): void
    {
        $this->question = mb_substr((string) request()->query('q', ''), 0, Assistant::MAX_QUESTION);
        $this->context = is_numeric(request()->query('v')) ? (int) request()->query('v') : null;
        if (trim($this->question) !== '') {
            $this->ask();
        }
    }

    public function ask(): void
    {
        $this->validate(['question' => ['required', 'string', 'max:'.Assistant::MAX_QUESTION]]);
        try {
            $query = Assistant::ask($this->user(), (int) Filament::getTenant()->getKey(), $this->question, $this->context);
        } catch (Throwable $e) {
            report($e);
            Notification::make()->title(__('asistente.respuestas.error'))->danger()->send();

            return;
        }
        $this->queryId = (int) $query->getKey();
        $this->comment = $this->note = '';
    }

    /** Answer to «¿A cuál se refiere?»: asks again within that procedure. */
    public function askWithin(int $versionId): void
    {
        $this->context = $versionId;
        $this->ask();
    }

    public function show(int $queryId): void
    {
        $this->queryId = $this->own($queryId) ? $queryId : null;
    }

    public function rate(int $rating): void
    {
        if (($query = $this->current()) !== null) {
            Assistant::rate($this->user(), $query, $rating, $this->comment);
            Notification::make()->title(__('asistente.valoracion.gracias'))->success()->send();
        }
    }

    public function sendToOwner(): void
    {
        if (($query = $this->current()) === null) {
            return;
        }
        try {
            Assistant::sendToOwner($this->user(), $query, $this->note);
        } catch (Throwable $e) {
            report($e);
            Notification::make()->title(__('asistente.duda.error'))->danger()->send();

            return;
        }
        Notification::make()->title(__('asistente.duda.enviada'))->success()->send();
    }

    public function current(): ?AssistantQuery
    {
        return $this->queryId === null ? null : $this->mine()->whereKey($this->queryId)->first();
    }

    /** @return Collection<int, AssistantQuery> */
    public function history(): Collection
    {
        return $this->mine()->latest('id')->limit(10)->get();
    }

    /** @return array{answer: ?string, links: list<array{label: string, url: string}>, options: list<array{version_id: int, label: string}>} */
    public function presentation(AssistantQuery $query): array
    {
        $user = $this->user();

        return [
            'answer' => Assistant::visibleAnswer($query, $user),
            'links' => Assistant::citations($query, $user),
            'options' => $query->outcome === 'aclaracion' ? Assistant::options($query, $user) : [],
        ];
    }

    private function mine(): \Illuminate\Database\Eloquent\Builder
    {
        return AssistantQuery::query()->where('user_id', $this->user()->getKey())->where('team_id', Filament::getTenant()?->getKey());
    }

    private function own(int $queryId): bool
    {
        return $this->mine()->whereKey($queryId)->exists();
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User && self::canAccess(), 403);

        return $user;
    }
}
