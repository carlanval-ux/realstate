<?php

declare(strict_types=1);

namespace App\Filament;

use App\Collaborators\CollaboratorAccess;
use App\Filament\Colaboradores\Pages\CollaboratorManual;
use App\Filament\Pages\UserManual;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Floating «Preguntar a CLARTO» chat on every page of the staff panels and of the collaborator panel (render hook
 * BODY_END, see ClartoServiceProvider). Stateless on the server: the conversation lives in the browser tab
 * (sessionStorage) and each question returns, at once, the entries the manual search finds among the sections of the
 * active modules. Staff get the staff manual; an external collaborator only ever gets the collaborators' manual.
 */
final class ManualChat extends Component
{
    public const PANELS = ['admin', 'app', CollaboratorAccess::PANEL];

    public const MAX_QUESTION = 300;

    /** Manual page of the panel (and tenant) the chat was rendered on: answers link there. */
    #[Locked]
    public string $manualUrl = '';

    /** Collaborator panel: informal wording («tú») and the collaborators' manual. */
    #[Locked]
    public bool $forCollaborators = false;

    public function mount(): void
    {
        $page = ManualGlobalSearchProvider::manualPage() ?? UserManual::class;
        $this->forCollaborators = $page::MANUAL === CollaboratorManual::MANUAL;
        $this->manualUrl = $page::getUrl();
    }

    /** Shown only to signed-in users, on the panels whose menu has a manual. */
    public static function renders(): bool
    {
        $panel = Filament::getCurrentPanel();

        return auth()->check() && $panel !== null && in_array($panel->getId(), self::PANELS, true)
            && ManualGlobalSearchProvider::manualPage() !== null;
    }

    /** @return array{entries: list<array{title: string, section: string, excerpt: string, url: string}>} */
    public function ask(string $question): array
    {
        abort_unless(auth()->check(), 403);
        $question = mb_substr(trim($question), 0, self::MAX_QUESTION);
        // Decided by who asks, not by the page: a collaborator never searches the staff manual.
        $manual = $this->forCollaborators || CollaboratorAccess::isCollaborator(auth()->user()) ? CollaboratorManual::class : UserManual::class;

        return ['entries' => array_map(fn (array $entry): array => [
            'title' => $entry['title'],
            'section' => $entry['section'] !== $entry['title'] ? $entry['section'] : '',
            'excerpt' => $entry['excerpt'],
            // No «?buscar=»: the page filter wants every word, a sentence would hide the very section linked.
            'url' => $this->manualUrl.'#'.$entry['anchor'],
        ], $question === '' ? [] : $manual::search($question, limit: 3, minCoverage: 0.5))];
    }

    public function render(): View
    {
        return view('filament.manual-chat');
    }
}
