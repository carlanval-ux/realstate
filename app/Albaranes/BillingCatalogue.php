<?php

declare(strict_types=1);

namespace App\Albaranes;

use Illuminate\Support\Facades\Cache;

/** Services offered on albarán lines: the billing program's catalogue when it exposes one, else config('facturador.servicios'). */
final class BillingCatalogue
{
    /** @return array<string, array{nombre: string, precio: float, conformidad: bool}> */
    public static function services(): array
    {
        $local = (array) config('facturador.servicios', []);
        if (config('facturador.driver') !== 'api' || ! config('facturador.enabled')) {
            return $local;
        }

        // ponytail: 10-minute cache; a price change in the billing program shows up after that.
        return Cache::remember('facturador:catalogo', 600, fn (): ?array => (new BillingApiTransport)->services()) ?: $local;
    }

    /** @return array<string, string> code => "code · name" for selects */
    public static function options(): array
    {
        $services = self::services();

        return array_combine(array_keys($services), array_map(fn (string $code, array $s): string => $code.' · '.$s['nombre'], array_keys($services), $services));
    }
}
