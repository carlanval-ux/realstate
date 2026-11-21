<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Communications\Communications;
use App\Models\Communication;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Liberu\RealEstate\Parties\Models\Party;
use Livewire\Attributes\Url;

/**
 * «Conversations» inbox: the team's clients with communications (last message, unread) on the left, the selected
 * client's thread with its composer on the right. Messages from numbers not yet linked to a client wait in
 * «WhatsApp to link».
 */
final class Conversations extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-inbox-stack';

    protected static ?string $slug = 'conversaciones';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.conversations';

    #[Url(as: 'cliente')]
    public ?int $cliente = null;

    public string $search = '';

    public static function getNavigationLabel(): string
    {
        return __('Conversations');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('filament.nav_groups.people_relationships');
    }

    public function getTitle(): string
    {
        return __('Conversations');
    }

    public static function canAccess(): bool
    {
        return filled(auth()->user()?->current_team_id);
    }

    public static function getNavigationBadge(): ?string
    {
        $n = Communications::unreadCount((int) auth()->user()?->current_team_id);

        return $n > 0 ? (string) $n : null;
    }

    public function mount(): void
    {
        $this->cliente ??= $this->threads()->first()?->party_id;
    }

    public function open(int $partyId): void
    {
        $this->cliente = Party::query()->forTeam($this->teamId())->whereKey($partyId)->exists() ? $partyId : null;
    }

    /** @return Collection<int, Communication> */
    public function threads(): Collection
    {
        return Communications::inbox($this->teamId(), trim($this->search) ?: null);
    }

    public function unlinked(): int
    {
        return Communication::query()->where('team_id', $this->teamId())->where('status', Communication::UNLINKED)->distinct()->count('phone');
    }

    private function teamId(): int
    {
        return (int) auth()->user()?->current_team_id;
    }
}
