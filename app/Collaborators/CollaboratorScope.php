<?php

declare(strict_types=1);

namespace App\Collaborators;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * What a collaborator may see of one model. Implemented by each module that shows records to collaborators
 * (e.g. «Solicitudes»: search requests sent to them, their own responses) and registered once at boot:
 *
 *   CollaboratorAccess::register(SearchRequest::class, new SearchRequestScope);
 *
 * Both methods receive an enabled collaborator (never staff, never a deactivated collaborator): deny by default.
 */
interface CollaboratorScope
{
    /** Restricts $query (a query of the registered model) to the records this collaborator may see. */
    public function scope(Builder $query, Collaborator $collaborator): Builder;

    /** Whether this collaborator may see this record (record pages, attachments, API, before sending a notice). */
    public function canSee(Collaborator $collaborator, Model $record): bool;
}
