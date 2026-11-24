<?php

declare(strict_types=1);

namespace App\Policies;

use App\Documents\CompanyDocument;
use App\Models\User;
use Filament\Facades\Filament;

/** «Documentación de la empresa»: only the company's management team (CompanyDocument::canAccess); only administrators delete. */
final class CompanyDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return CompanyDocument::canAccess($user, Filament::getTenant()?->getKey());
    }

    public function view(User $user, CompanyDocument $document): bool
    {
        return CompanyDocument::canAccess($user, $document->team_id);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, CompanyDocument $document): bool
    {
        return $this->view($user, $document);
    }

    public function delete(User $user, CompanyDocument $document): bool
    {
        return CompanyDocument::canManageAccess($user, $document->team_id);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
