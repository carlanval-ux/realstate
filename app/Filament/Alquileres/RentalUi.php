<?php

declare(strict_types=1);

namespace App\Filament\Alquileres;

use App\Rentals\RentalAccess;
use App\Rentals\RentalRefused;
use Closure;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;

/** Small helpers shared by the rental screens: translated options, permission checks and refusals as notifications. */
final class RentalUi
{
    /**
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    public static function options(string $group, array $keys): array
    {
        return collect($keys)->mapWithKeys(fn (string $key): array => [$key => __("clarto.alquileres.{$group}.{$key}")])->all();
    }

    public static function label(string $group, ?string $key): string
    {
        return $key === null || $key === '' ? '—' : __("clarto.alquileres.{$group}.{$key}");
    }

    public static function team(): int|string|null
    {
        return Filament::getTenant()?->getKey() ?? auth()->user()?->current_team_id;
    }

    public static function can(string $permission): bool
    {
        return RentalAccess::allows(auth()->user(), $permission);
    }

    /**
     * Run a rental operation; a refusal (rule, permission, module off, dates taken) becomes a red notification and the
     * action stays open.
     *
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public static function attempt(Closure $operation, ?string $done = null): mixed
    {
        try {
            $result = $operation();
        } catch (RentalRefused $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            throw new Halt;
        }
        Notification::make()->success()->title($done ?? __('clarto.alquileres.actions.done'))->send();

        return $result;
    }

    /**
     * KeyValue «supply → porcentaje:25 | importe:30 | incluido» ⇄ the contract's utilities array.
     *
     * @param  array<string, string>|null  $state
     * @return array<string, array{modo: string, valor?: float}>
     */
    public static function utilitiesFromForm(?array $state): array
    {
        $rules = [];
        foreach ($state ?? [] as $supply => $text) {
            [$mode, $value] = array_pad(explode(':', trim((string) $text), 2), 2, null);
            $mode = in_array($mode, ['porcentaje', 'importe'], true) ? $mode : 'incluido';
            $rules[trim((string) $supply)] = $mode === 'incluido' ? ['modo' => 'incluido'] : ['modo' => $mode, 'valor' => (float) str_replace(',', '.', (string) $value)];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public static function utilitiesToForm(?array $rules): array
    {
        return collect($rules ?? [])->map(fn (array $r): string => $r['modo'].(isset($r['valor']) ? ':'.$r['valor'] : ''))->all();
    }
}
