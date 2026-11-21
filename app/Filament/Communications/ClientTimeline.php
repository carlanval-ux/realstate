<?php

declare(strict_types=1);

namespace App\Filament\Communications;

use App\Communications\Communications;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Liberu\RealEstate\Parties\Models\Party;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A client's communications, GoHighLevel style: every WhatsApp, SMS and e-mail (sent or received, by the team, by
 * rentals, signatures or system e-mails) in one chronological thread, with a composer and channel selector at the
 * bottom. Only contacts of the user's current team can be opened. Opening the thread marks incoming messages read.
 */
final class ClientTimeline extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    #[Locked]
    public int $partyId;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(int $partyId): void
    {
        $this->partyId = $partyId;
        $this->party();   // 404 for another team's contact
        $this->form->fill();
    }

    public function party(): Party
    {
        return Party::query()->forTeam((int) auth()->user()?->current_team_id)->findOrFail($this->partyId);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components(CommunicationsUi::fields($this->party()));
    }

    public function send(): void
    {
        $data = $this->form->getState();
        if (CommunicationsUi::deliver($this->party(), $data)) {
            $this->form->fill(['channel' => $data['channel'] ?? 'whatsapp', 'to' => $data['to'], 'purpose' => 'servicio']);
        }
    }

    public function render(): View
    {
        $party = $this->party();
        Communications::markRead($party);

        return view('filament.communications.timeline', ['party' => $party, 'items' => Communications::timeline($party)]);
    }
}
