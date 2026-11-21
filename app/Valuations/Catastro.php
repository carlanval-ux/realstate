<?php

declare(strict_types=1);

namespace App\Valuations;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Public Catastro services (OVC «Callejero» and «Coordenadas», JSON, no key): find the dwelling by address or cadastral
 * reference. An address with several flats answers «varias» and the visitor picks one; the detail of one reference
 * splits the dwelling, the common elements and the annexes (garage, storage), so nothing is added twice.
 *
 * Answers are cached (config valoraciones.catastro.cache_horas); a timeout or a 5xx answers «catastro_no_disponible»
 * and is not cached.
 */
final class Catastro
{
    public const LICENCIA = 'Información catastral no protegida de los servicios web libres, citando «Dirección General del Catastro» y la fecha de consulta (uso comercial pendiente de confirmación de la DG del Catastro).';

    private const TYPES = [
        'CL' => ['CALLE', 'CL', 'C', 'CARRER', 'CR', 'CARRERO'],
        'AV' => ['AVENIDA', 'AVDA', 'AV', 'AVINGUDA', 'AVGDA'],
        'PZ' => ['PLAZA', 'PZA', 'PZ', 'PLACA', 'PL'],
        'PS' => ['PASEO', 'PASSEIG', 'PS', 'PG', 'PSO'],
        'CM' => ['CAMINO', 'CAMI', 'CM'],
        'RD' => ['RONDA', 'RD'],
        'TR' => ['TRAVESIA', 'TRAVESSERA', 'TR'],
        'GV' => ['GRAN VIA'],
        'UR' => ['URBANIZACION', 'URB'],
    ];

    /** Visitor spellings → the name Catastro uses for the municipality search. */
    private const MUNICIPALITY_ALIASES = [
        'PUERTO DE SAGUNTO' => 'SAGUNT', 'PORT DE SAGUNT' => 'SAGUNT', 'PUERTO SAGUNTO' => 'SAGUNT', 'SAGUNTO' => 'SAGUNT',
        'GRAO DE GANDIA' => 'GANDIA', 'PLAYA DE GANDIA' => 'GANDIA', 'PLATJA DE GANDIA' => 'GANDIA',
        'VALENCIA' => 'VALENCIA', 'VALENCIA CIUDAD' => 'VALENCIA',
    ];

    private ?string $consulted = null;

    /** @var list<string> */
    private array $services = [];

    /**
     * @return array{estado: string, candidatos: list<array<string, mixed>>, fuente: array<string, string>, mensaje?: string, sugerencias?: list<string>}
     */
    public function search(?string $address, ?string $municipality, ?string $province): array
    {
        $code = Provinces::code($province);
        if (Provinces::foral($code)) {
            // Álava, Gipuzkoa, Bizkaia, Navarra: foral cadastres, not in the OVC. The visitor goes on without Catastro.
            return $this->answer('no_encontrada', [], __('clarto.valoraciones.catastro.foral', ['provincia' => Provinces::name($code)]), motivo: 'catastro_foral');
        }
        try {
            $province = self::province($province);
            $municipality = $this->municipality($province, (string) $municipality);
            $parts = self::parseAddress((string) $address);
            if ($municipality === null || $parts['nombre'] === '' || $parts['numero'] === null) {
                return $this->answer('no_encontrada', [], __('clarto.valoraciones.catastro.sin_direccion'));
            }
            $street = $this->street($province, $municipality, $parts['nombre'], $parts['tipo']);
            if ($street === null) {
                return $this->answer('no_encontrada', [], __('clarto.valoraciones.catastro.sin_calle'));
            }

            $json = $this->call('COVCCallejero', 'Consulta_DNPLOC', [
                'Provincia' => $province, 'Municipio' => $municipality, 'Sigla' => $street['tv'], 'Calle' => $street['nv'], 'Numero' => $parts['numero'],
            ])['consulta_dnplocResult'] ?? [];

            $candidates = $this->candidates($json);
            if ($candidates === []) {
                $near = array_values(array_unique(array_map(fn (array $n): string => (string) ($n['num']['pnp'] ?? ''), self::items($json['numerero']['nump'] ?? []))));

                return $this->answer('no_encontrada', [], __('clarto.valoraciones.catastro.sin_numero'), array_values(array_filter($near)));
            }

            return $this->choose($candidates, $parts);
        } catch (CatastroUnavailable) {
            return $this->answer('catastro_no_disponible', [], __('clarto.valoraciones.catastro.no_disponible'));
        }
    }

    /** @return array{estado: string, candidatos: list<array<string, mixed>>, fuente: array<string, string>, mensaje?: string} */
    public function byReference(string $rc): array
    {
        $rc = Str::upper(preg_replace('/\s+/', '', $rc) ?? '');
        if (! preg_match('/^[0-9A-Z]{14}([0-9A-Z]{4}[A-Z]{2})?$/', $rc)) {
            return $this->answer('no_encontrada', [], __('clarto.valoraciones.catastro.rc_no_valida'));
        }

        try {
            $candidates = $this->candidates($this->call('COVCCallejero', 'Consulta_DNPRC', ['RefCat' => $rc])['consulta_dnprcResult'] ?? []);

            return $candidates === []
                ? $this->answer('no_encontrada', [], __('clarto.valoraciones.catastro.rc_no_encontrada'))
                : $this->choose($candidates, []);
        } catch (CatastroUnavailable) {
            return $this->answer('catastro_no_disponible', [], __('clarto.valoraciones.catastro.no_disponible'));
        }
    }

    /**
     * Full detail of one dwelling (20-character reference): surfaces split, annexes, coordinates. Null if not found.
     *
     * @return array<string, mixed>|null
     *
     * @throws CatastroUnavailable
     */
    public function detail(string $rc): ?array
    {
        $rc = Str::upper(preg_replace('/\s+/', '', $rc) ?? '');
        if (strlen($rc) !== 20) {
            return null;
        }
        $candidates = $this->candidates($this->call('COVCCallejero', 'Consulta_DNPRC', ['RefCat' => $rc])['consulta_dnprcResult'] ?? []);
        $one = $candidates[0] ?? null;

        return $one === null ? null : [...$one, 'fuente' => $this->source()];
    }

    /** @return array<string, string> */
    public function source(): array
    {
        return [
            'nombre' => 'Sede Electrónica del Catastro',
            'servicio' => 'OVC '.implode(', ', array_unique($this->services ?: ['Callejero'])),
            'consultado' => $this->consulted ?? now()->toIso8601String(),
            'licencia' => self::LICENCIA,
            'url' => 'https://www.sedecatastro.gob.es',
        ];
    }

    /**
     * @param  array<string, mixed>  $json  consulta_dnplocResult / consulta_dnprcResult
     * @return list<array<string, mixed>>
     */
    private function candidates(array $json): array
    {
        if (isset($json['bico']['bi'])) {
            return [$this->candidate($json['bico']['bi']['idbi']['rc'] ?? [], $json['bico']['bi'], self::items($json['bico']['lcons'] ?? []))];
        }

        return array_map(fn (array $item): array => $this->candidate($item['rc'] ?? [], $item, null), self::items($json['lrcdnp']['rcdnp'] ?? []));
    }

    /**
     * @param  array<string, string>  $rc
     * @param  array<string, mixed>  $bi  dt + debi
     * @param  list<array<string, mixed>>|null  $units  lcons (only in the detail of one reference)
     * @return array<string, mixed>
     */
    private function candidate(array $rc, array $bi, ?array $units): array
    {
        $dt = (array) ($bi['dt'] ?? []);
        $lourb = (array) ($dt['locs']['lous']['lourb'] ?? []);
        $dir = (array) ($lourb['dir'] ?? []);
        $in = (array) ($lourb['loint'] ?? []);
        $whole = in_array($in['es'] ?? '', ['T', ''], true) && in_array($in['pt'] ?? '', ['OD', ''], true);   // the whole building
        $parcel = ($rc['pc1'] ?? '').($rc['pc2'] ?? '');
        $total = self::number($bi['debi']['sfc'] ?? null);

        $address = trim(($dir['tv'] ?? '').' '.($dir['nv'] ?? '').' '.ltrim((string) ($dir['pnp'] ?? ''), '0'));
        if (! $whole) {
            $address .= implode('', array_map(fn ($label, $value): string => filled($value) ? ", {$label} {$value}" : '', ['Esc', 'Pl', 'Pt'], [$in['es'] ?? null, $in['pt'] ?? null, $in['pu'] ?? null]));
        }

        $out = [
            'rc' => $parcel.($rc['car'] ?? '').($rc['cc1'] ?? '').($rc['cc2'] ?? ''),
            'direccion' => $address,
            'municipio' => (string) ($dt['nm'] ?? ''),
            'provincia' => (string) ($dt['np'] ?? ''),
            'municipio_ine' => isset($dt['loine']['cp'], $dt['loine']['cm']) ? sprintf('%02d%03d', (int) $dt['loine']['cp'], (int) $dt['loine']['cm']) : null,
            'codigo_postal' => filled($lourb['dp'] ?? null) ? (string) $lourb['dp'] : null,
            'uso' => (string) ($bi['debi']['luso'] ?? ''),
            'planta' => $whole ? null : ($in['pt'] ?? null),
            'puerta' => $whole ? null : ($in['pu'] ?? null),
            'escalera' => $whole ? null : ($in['es'] ?? null),
            'superficie_total_catastro_m2' => $total,
            'superficie_construida_m2' => $total,
            'superficie_vivienda_m2' => null,
            'superficie_elementos_comunes_m2' => null,
            'anexos' => [],
            'desglose' => false,
            'ano_construccion' => is_numeric($bi['debi']['ant'] ?? null) ? (int) $bi['debi']['ant'] : null,
            'tipologia' => null,
            'coordenadas' => null,
        ];

        if ($units !== null) {
            $living = $common = 0.0;
            foreach ($units as $unit) {
                $m2 = (float) self::number($unit['dfcons']['stl'] ?? null);
                $kind = Str::upper((string) ($unit['lcd'] ?? ''));
                match (true) {
                    $kind === 'VIVIENDA' => $living += $m2,
                    $kind === 'ELEMENTOS COMUNES' => $common += $m2,
                    default => $out['anexos'][] = [
                        'tipo' => match ($kind) { 'APARCAMIENTO' => 'garaje', 'ALMACEN' => 'trastero', default => 'otro' },
                        'descripcion' => ['ALMACEN' => 'Almacén', 'APARCAMIENTO' => 'Aparcamiento'][$kind] ?? Str::ucfirst(Str::lower($kind)),
                        'superficie_m2' => $m2,
                        'planta' => $unit['dt']['lourb']['loint']['pt'] ?? null,
                        'rc' => null,
                    ],
                };
                if ($kind === 'VIVIENDA' && $out['tipologia'] === null) {
                    $out['tipologia'] = Str::ucfirst(Str::lower((string) ($unit['dvcons']['dtip'] ?? '')));
                }
            }
            $out['superficie_vivienda_m2'] = $living ?: null;
            $out['superficie_elementos_comunes_m2'] = $common ?: null;
            // Built area of the dwelling including its share of common elements, WITHOUT annexes (valued apart).
            $out['superficie_construida_m2'] = $living > 0 ? $living + $common : $total;
            $out['desglose'] = true;
            $out['coordenadas'] = $parcel !== '' ? $this->coordinates($parcel) : null;
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @param  array<string, ?string>  $parts
     */
    private function choose(array $candidates, array $parts): array
    {
        // Dwellings first; the planta/puerta written by the visitor narrows the list when it matches.
        usort($candidates, fn (array $a, array $b): int => [($a['uso'] !== 'Residencial'), $a['rc']] <=> [($b['uso'] !== 'Residencial'), $b['rc']]);
        $same = fn (?string $a, ?string $b): bool => $a !== null && $b !== null && ltrim(Str::upper($a), '0') === ltrim(Str::upper($b), '0');
        $narrowed = array_values(array_filter($candidates, fn (array $c): bool => ($parts['planta'] ?? null) !== null
            && $same($c['planta'], $parts['planta']) && (($parts['puerta'] ?? null) === null || $same($c['puerta'], $parts['puerta']))));
        if (count($narrowed) === 1) {
            $candidates = $narrowed;
        }

        if (count($candidates) === 1 && ! $candidates[0]['desglose']) {
            $candidates[0] = $this->detail($candidates[0]['rc']) ?? $candidates[0];
            unset($candidates[0]['fuente']);
        }
        if (count($candidates) > 1) {
            $coordinates = $this->coordinates(substr((string) $candidates[0]['rc'], 0, 14));
            $candidates = array_map(fn (array $c): array => [...$c, 'coordenadas' => $c['coordenadas'] ?? (str_starts_with((string) $c['rc'], substr((string) $candidates[0]['rc'], 0, 14)) ? $coordinates : null)], $candidates);
        }

        return $this->answer(count($candidates) === 1 ? 'ok' : 'varias', $candidates);
    }

    /** @return array{lat: float, lon: float}|null */
    private function coordinates(string $parcel): ?array
    {
        try {
            $geo = $this->call('COVCCoordenadas', 'Consulta_CPMRC', ['SRS' => 'EPSG:4326', 'RefCat' => $parcel])['Consulta_CPMRCResult']['coordenadas']['coord'] ?? null;
            $geo = self::items($geo)[0]['geo'] ?? null;
        } catch (CatastroUnavailable) {
            return null;
        }

        return is_numeric($geo['ycen'] ?? null) ? ['lat' => round((float) $geo['ycen'], 6), 'lon' => round((float) $geo['xcen'], 6)] : null;
    }

    /** @throws CatastroUnavailable */
    private function municipality(string $province, string $name): ?string
    {
        $wanted = self::normalize($name);
        $wanted = self::MUNICIPALITY_ALIASES[$wanted] ?? $wanted;
        if ($wanted === '') {
            return null;
        }
        $list = self::items($this->call('COVCCallejero', 'ObtenerMunicipios', ['Provincia' => $province, 'Municipio' => $wanted])['consulta_municipieroResult']['municipiero']['muni'] ?? []);
        $names = array_map(fn (array $m): string => (string) ($m['nm'] ?? ''), $list);
        foreach ($names as $nm) {
            if (in_array($wanted, array_map('trim', explode('/', self::normalize($nm))), true) || self::normalize($nm) === $wanted) {
                return $nm;
            }
        }

        return count($names) === 1 ? $names[0] : null;
    }

    /**
     * @return array{tv: string, nv: string}|null
     *
     * @throws CatastroUnavailable
     */
    private function street(string $province, string $municipality, string $name, ?string $type): ?array
    {
        $list = self::items($this->call('COVCCallejero', 'ObtenerCallejero', ['Provincia' => $province, 'Municipio' => $municipality, 'NomVia' => $name])['consulta_callejeroResult']['callejero']['calle'] ?? []);
        $streets = array_map(fn (array $c): array => ['tv' => (string) ($c['dir']['tv'] ?? ''), 'nv' => (string) ($c['dir']['nv'] ?? '')], $list);
        $rank = fn (array $s): int => (self::normalize($s['nv']) === $name ? 0 : 2) + ($type !== null && $s['tv'] === $type ? 0 : 1);
        usort($streets, fn (array $a, array $b): int => $rank($a) <=> $rank($b));

        return $streets[0] ?? null;
    }

    /**
     * «Calle de Cuba 22, 2ª pta 3» → tipo CL, nombre CUBA, número 22, planta 2, puerta 3.
     *
     * @return array{tipo: ?string, nombre: string, numero: ?string, planta: ?string, puerta: ?string}
     */
    public static function parseAddress(string $text): array
    {
        $text = self::normalize(str_replace(['º', 'ª', '°', '/'], ' ', $text));
        $type = null;
        foreach (self::TYPES as $code => $words) {
            foreach ($words as $word) {
                if (preg_match('/^'.preg_quote($word, '/').'(\s|$)/', $text)) {
                    [$type, $text] = [$code, trim(substr($text, strlen($word)))];
                    break 2;
                }
            }
        }
        if (! preg_match('/^(.*?)\s*(?:\bN(?:UM(?:ERO)?)?\s*)?(\d+)\b(.*)$/', $text, $m)) {
            return ['tipo' => $type, 'nombre' => self::streetName($text), 'numero' => null, 'planta' => null, 'puerta' => null];
        }
        $rest = ' '.$m[3].' ';
        $floor = preg_match('/\b(?:PL(?:ANTA)?|PISO)\s*(-?\d+|BJ|BAJO)\b/', $rest, $f) ? $f[1] : null;
        $door = preg_match('/\b(?:PT[AE]?|PUERTA|PORTA)\s*([A-Z0-9]{1,3})\b/', $rest, $d) ? $d[1] : null;
        if ($floor === null && preg_match('/^\W*(\d{1,2})\W+([A-Z0-9]{1,3})\b/', $m[3], $fd)) {
            [$floor, $door] = [$fd[1], $door ?? $fd[2]];
        }

        return ['tipo' => $type, 'nombre' => self::streetName($m[1]), 'numero' => $m[2], 'planta' => $floor, 'puerta' => $door];
    }

    private static function streetName(string $name): string
    {
        return trim((string) preg_replace('/^(DE LA|DE LES|DE LOS|DE LAS|DELS|DEL|DE|D)\s+/', '', trim($name)));
    }

    /** Any usual spelling («Alicante», «Illes Balears», «Santa Cruz de Tenerife») → the OVC's («ALACANT», «S.C. TENERIFE»). */
    private static function province(?string $province): string
    {
        $p = self::normalize((string) $province);

        return $p === '' ? 'VALENCIA' : (Provinces::catastroName(Provinces::code($province)) ?? $p);
    }

    public static function normalize(string $text): string
    {
        $text = Str::upper(Str::ascii($text));

        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^A-Z0-9\/\- ]+/', ' ', str_replace(["'", '’'], ' ', $text))));
    }

    /** «1.133» (thousands) → 1133.0 */
    private static function number(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = str_replace(['.', ','], ['', '.'], (string) $value);

        return is_numeric($value) ? (float) $value : null;
    }

    /** Catastro returns a single item as an object and several as a list. */
    private static function items(mixed $value): array
    {
        if (! is_array($value) || $value === []) {
            return [];
        }

        return array_is_list($value) ? $value : [$value];
    }

    /**
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     *
     * @throws CatastroUnavailable
     */
    private function call(string $service, string $method, array $query): array
    {
        $this->services[] = $method;
        $url = config('valoraciones.catastro.url')."/{$service}.svc/json/{$method}";
        $hit = Cache::remember('clarto:catastro:'.sha1($url.'?'.http_build_query($query)), now()->addHours((int) config('valoraciones.catastro.cache_horas', 168)), function () use ($url, $query): array {
            try {
                $response = Http::timeout((int) config('valoraciones.catastro.timeout', 8))->acceptJson()->get($url, $query);
            } catch (ConnectionException $e) {
                throw new CatastroUnavailable($e->getMessage(), previous: $e);
            }
            $json = $response->json();
            if (! $response->successful() || ! is_array($json)) {
                throw new CatastroUnavailable('Catastro HTTP '.$response->status());
            }

            return ['at' => now()->toIso8601String(), 'json' => $json];
        });
        $this->consulted = $this->consulted === null || $hit['at'] < $this->consulted ? $hit['at'] : $this->consulted;

        return (array) $hit['json'];
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @param  list<string>  $suggestions
     */
    private function answer(string $state, array $candidates, ?string $message = null, array $suggestions = [], ?string $motivo = null): array
    {
        return array_filter([
            'estado' => $state,
            'candidatos' => $candidates,
            'fuente' => $this->source(),
            'mensaje' => $message,
            'sugerencias' => $suggestions ?: null,
            'motivo' => $motivo,
        ], fn ($v): bool => $v !== null);
    }
}

