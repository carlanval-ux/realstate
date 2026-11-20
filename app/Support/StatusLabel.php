<?php

declare(strict_types=1);

namespace App\Support;

use BackedEnum;
use Illuminate\Support\Facades\Lang;

/** Translated label of a property status (lang/{locale}/clarto.php), the raw value as a last resort. */
final class StatusLabel
{
    public static function property(BackedEnum|string|null $status): string
    {
        $value = $status instanceof BackedEnum ? (string) $status->value : (string) $status;

        return Lang::has("clarto.property_statuses.{$value}") ? __("clarto.property_statuses.{$value}") : str($value)->headline()->toString();
    }
}
