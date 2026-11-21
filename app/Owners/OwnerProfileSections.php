<?php

declare(strict_types=1);

namespace App\Owners;

use Filament\Schemas\Components\Tabs\Tab;

/**
 * Extension point of the owner 360 page (Propietarios): other features add their own tab without editing it.
 * Register once from a service provider's boot():
 *
 *   OwnerProfileSections::register('albaranes', fn (): Tab => Tab::make(__('…'))->schema([...]), sort: 70);
 *
 * The factory returns a Filament Tab; closures inside it receive the owner as `?Party $record`, like any
 * infolist component. Built-in tabs use sorts 10–60 (Datos 10, Inmuebles 20, Encargos 30, Valoraciones 40,
 * Documentos 50, Historial 60). Registering the same key again replaces the previous tab.
 */
final class OwnerProfileSections
{
    /** @var array<string, array{0: callable(): Tab, 1: int}> */
    private static array $sections = [];

    /** @param callable(): Tab $tab */
    public static function register(string $key, callable $tab, int $sort = 100): void
    {
        self::$sections[$key] = [$tab, $sort];
    }

    /** @return list<array{key: string, tab: callable(): Tab, sort: int}> */
    public static function all(): array
    {
        $out = [];
        foreach (self::$sections as $key => [$tab, $sort]) {
            $out[] = ['key' => $key, 'tab' => $tab, 'sort' => $sort];
        }
        usort($out, fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

        return $out;
    }
}
