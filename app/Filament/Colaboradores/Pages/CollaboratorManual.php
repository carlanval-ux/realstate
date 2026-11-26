<?php

declare(strict_types=1);

namespace App\Filament\Colaboradores\Pages;

use App\Filament\Pages\UserManual;

/**
 * «Manual de uso» of the collaborator panel: resources/manual/colaboradores.{locale}.md, with the same search box,
 * index and module-dependent sections as the staff manual, and the only manual the chat and the global search of
 * this panel read.
 */
final class CollaboratorManual extends UserManual
{
    public const MANUAL = 'colaboradores';

    protected static ?int $navigationSort = 90;

    /** Own statics: ClartoNavigation sets the staff manual's group, which must not move this one. */
    protected static string|\UnitEnum|null $navigationGroup = null;
}
