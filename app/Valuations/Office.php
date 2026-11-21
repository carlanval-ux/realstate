<?php

declare(strict_types=1);

namespace App\Valuations;

/**
 * The CLARTO office that handles a property, from the property's postcode (GHL strategy §8.1, L-11), and the booking
 * link of its «Visita de valoración» calendar (panel setting, else .env).
 */
final class Office
{
    public const VALENCIA = 'Valencia centro';

    public const SAGUNTO = 'Puerto de Sagunto';

    public const BELLREGUARD = 'Bellreguard';

    /** office => settings column / .env suffix of its calendar link */
    public const CALENDARS = [self::VALENCIA => 'cita_valencia', self::SAGUNTO => 'cita_sagunto', self::BELLREGUARD => 'cita_bellreguard'];

    public static function forPostalCode(?string $postalCode): string
    {
        $cp = preg_replace('/\D/', '', (string) $postalCode) ?? '';

        return match (true) {
            str_starts_with($cp, '465'), in_array($cp, ['12590', '12592', '12593', '12600'], true) => self::SAGUNTO,
            str_starts_with($cp, '467'), str_starts_with($cp, '037') => self::BELLREGUARD,
            default => self::VALENCIA,
        };
    }

    /** True when the postcode is outside the province of Valencia and the areas above (the team checks the zone). */
    public static function outOfArea(?string $postalCode): bool
    {
        $cp = preg_replace('/\D/', '', (string) $postalCode) ?? '';

        return $cp !== '' && ! str_starts_with($cp, '46') && self::forPostalCode($cp) === self::VALENCIA;
    }

    /** Booking link of the office's calendar, with utm_content to measure where it was clicked (correo1, pdf, gracias). */
    public static function bookingUrl(string $office, ?string $source = null): ?string
    {
        $column = self::CALENDARS[$office] ?? self::CALENDARS[self::VALENCIA];
        $url = ValuationSetting::current()->getAttribute($column) ?: config('valoraciones.citas.'.$column);
        if (blank($url)) {
            return null;
        }

        return $source === null ? (string) $url : $url.(str_contains((string) $url, '?') ? '&' : '?').'utm_content='.rawurlencode($source);
    }
}
