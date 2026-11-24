<?php

declare(strict_types=1);

namespace App\Policies;

use App\Documents\CompanyDocument;
use App\Documents\CompanyDocumentFolder;
use App\Models\User;
use Filament\Facades\Filament;

/**
 * «Documentación de la empresa» (documents and folders): only the company's responsables (CompanyDocument::canAccess:
 * super administrators, administrators and the owner of the company).
 */
final class CompanyDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return CompanyDocument::canAccess($user, Filament::getTenant()?->getKey());
    }

    public function view(User $user, CompanyDocument|CompanyDocumentFolder $record): bool
    {
        return CompanyDocument::canAccess($user, $record->team_id);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, CompanyDocument|CompanyDocumentFolder $record): bool
    {
        return $this->view($user, $record);
    }

    public function delete(User $user, CompanyDocument|CompanyDocumentFolder $record): bool
    {
        return $this->view($user, $record);   // a folder with content refuses itself (CompanyDocumentFolder::deleting)
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
