<?php

declare(strict_types=1);

namespace App\Modules;

/** What «Módulos» shows for a CLARTO module, most blocking first. */
enum ModuleStatus: string
{
    /** Switched off by an administrator (or by MODULES_DISABLED): no new operations, history kept. */
    case Disabled = 'disabled';

    /** Switched on, but an official package it builds on is not installed or did not boot. */
    case Failed = 'failed';

    /** Switched on, but its implementation is not in this build yet (it lives on another branch). */
    case NotImplemented = 'not_implemented';

    /** Switched on and implemented, but credentials or a session are missing. */
    case PendingConfiguration = 'pending_configuration';

    case Enabled = 'enabled';

    public function label(): string
    {
        return match ($this) {
            self::Disabled => __('Disabled'),
            self::Failed => __('Failed'),
            self::NotImplemented => __('Pending implementation'),
            self::PendingConfiguration => __('Pending configuration'),
            self::Enabled => __('Enabled'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Enabled => 'success',
            self::PendingConfiguration, self::NotImplemented => 'warning',
            self::Failed => 'danger',
            self::Disabled => 'gray',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Enabled => 'heroicon-m-check-circle',
            self::PendingConfiguration => 'heroicon-m-cog-6-tooth',
            self::NotImplemented => 'heroicon-m-wrench-screwdriver',
            self::Failed => 'heroicon-m-exclamation-triangle',
            self::Disabled => 'heroicon-m-minus-circle',
        };
    }
}
