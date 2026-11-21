<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Valuations\Catastro;
use App\Valuations\Provinces;
use App\Valuations\Xls;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use RuntimeException;

/**
 * Refreshes the dated price snapshot of the valuations (resources/valoraciones/precios.json) for the whole of Spain,
 * only from sources whose automated commercial reuse is allowed (docs/clarto/valoraciones.md, «Fuentes»):
 *  - MIVAU appraised value of free-market housing: municipalities > 25.000 inhabitants (35103500, by building age, with
 *    the number of appraisals) and provinces (35101000 + number of appraisals 35103000) — latest quarter published;
 *  - INE IPV by autonomous community (table 80270, CC BY 4.0): to index a base whose quarter is older than the IPV;
 *  - Registradores Open Data, sales by province (ODbL 1.0): context only;
 *  - INE list of municipalities (codes): MIVAU names → INE codes, the key Catastro returns.
 * Hand-kept parts of the snapshot (postcode series and exceptions, licence-pending series, aliases, annexes,
 * calibration) are preserved. Without --guardar it only prints what would change.
 *
 *   php artisan clarto:valoraciones-datos [--dir=/tmp/fuentes] [--guardar] [--etiqueta=2027-02-15]
 */
final class ImportValuationPrices extends Command
{
    protected $signature = 'clarto:valoraciones-datos
        {--dir= : Read the source files from this folder (file names as in SOURCES) instead of downloading them}
        {--guardar : Write resources/valoraciones/precios.json; without it only the summary is printed}
        {--etiqueta= : Version label of the snapshot (default: today)}
        {--ine= : URL or path of the INE list of municipalities (default: diccionarioAA.xlsx of this year, else the last)}
        {--registradores= : URL or path of the Registradores CSV (its name changes every quarter: take it from the dataset page)}';

    protected $description = 'Refresh the nationwide price snapshot of the valuations (MIVAU, INE IPV, Registradores)';

    /** key => [file name in --dir, default URL] */
    public const SOURCES = [
        'mivau_municipios' => ['35103500.xls', 'https://cdn.transportes.gob.es/estadistica/BoletinOnline2/35103500.xls'],
        'mivau_provincias' => ['35101000.xls', 'https://cdn.transportes.gob.es/estadistica/BoletinOnline2/35101000.xls'],
        'mivau_tasaciones' => ['35103000.xls', 'https://cdn.transportes.gob.es/estadistica/BoletinOnline2/35103000.xls'],
        'ine_ipv' => ['ipv_80270.json', 'https://servicios.ine.es/wstempus/js/ES/DATOS_TABLA/80270?nult=8'],
        'registradores' => ['registradores.csv', 'https://opendata.registradores.org/data-integration/compraventas-residencial-trimestres-provincias-es/RP_ComprvResid_2007-2t2026.csv'],
        'ine_municipios' => ['diccionario.xlsx', 'https://www.ine.es/daco/daco42/codmun/diccionario%s.xlsx'],
    ];

    /** MIVAU spellings that do not match the INE name (normalised MIVAU name => INE code). */
    private const MUNICIPALITY_ALIASES = [
        'SANTA CRUZ DETENERIFE' => '38038', 'MAHON' => '07032', 'PALMA DE MALLORCA' => '07040', 'SANTA EULALIA DEL RIO' => '07054',
        'SANTA COLOMA GRAMANET' => '08245', 'VITORIA' => '01059',
    ];

    private const STOPWORDS = ['DE', 'DEL', 'LA', 'LAS', 'EL', 'LOS', 'L', 'D', 'Y', 'I', 'A', 'O', 'ES', 'SA', 'SES', 'ELS', 'LES', 'LO'];

    public function handle(): int
    {
        try {
            $ine = $this->ineMunicipalities($this->source('ine_municipios'));
            [$period, $rows] = $this->municipalSeries(Xls::read($this->source('mivau_municipios')));
            [$matched, $unmatched] = $this->match($rows, $ine);
            $provinces = $this->provinces(Xls::read($this->source('mivau_provincias')), Xls::read($this->source('mivau_tasaciones')));
            $ipv = $this->ipv((array) json_decode($this->source('ine_ipv'), true, 512, JSON_THROW_ON_ERROR));
        } catch (\Throwable $e) {
            $this->error('No se pudo leer una fuente: '.$e->getMessage());

            return self::FAILURE;
        }

        $path = resource_path('valoraciones/precios.json');
        $old = (array) json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        try {
            $registry = $this->registradores($this->source('registradores'));
        } catch (\Throwable $e) {
            // Context only. Its portal rejects non-browser downloads: download the CSV in a browser and pass --registradores=<file>.
            $registry = array_filter(array_map(fn (array $p): ?array => $p['registradores'] ?? null, (array) ($old['provincias'] ?? [])));
            $this->warn('Registradores no disponible ('.$e->getMessage().'): se conservan los datos anteriores ('.count($registry).' provincias). Descárguelo con el navegador y use --registradores=<fichero>.');
        }
        $new = $this->merge($old, $period, $matched, $ine, $provinces, $ipv, $registry);

        $before = collect((array) $old['ambitos'])->filter(fn (array $a): bool => collect($a['series'] ?? [])->contains('fuente', 'mivau_tasado'))->count();
        $this->line("MIVAU valor tasado municipal: {$period}, ".count($matched).' municipios con serie (antes '.$before.').');
        $this->line('Provincias con valor tasado: '.count(array_filter($provinces, fn (array $p): bool => isset($p['eur_m2']))).' de '.count(Provinces::ALL).' ('.($provinces['28']['periodo'] ?? '?').').');
        $this->line('IPV (INE 80270): '.count($ipv).' comunidades, último periodo '.collect($ipv)->map(fn (array $s): string => (string) array_key_last($s['indices']))->max().'.');
        $this->line('Registradores: '.count($registry).' provincias, periodo '.collect($registry)->max('periodo').'.');
        foreach ($unmatched as $name) {
            $this->warn("Sin código INE (añadir a MUNICIPALITY_ALIASES): {$name}");
        }
        if ($unmatched !== [] || count($matched) < 200 || count($provinces) < 50) {
            $this->error('Datos incompletos: no se guarda nada.');

            return self::FAILURE;
        }
        if (! $this->option('guardar')) {
            $this->line('Sin --guardar: no se ha escrito nada.');

            return self::SUCCESS;
        }

        file_put_contents($path, self::json($new));
        $this->info("Guardado {$path} (versión {$new['version']}). Revise el diff y súbalo con la rama (docs/clarto/valoraciones.md).");

        return self::SUCCESS;
    }

    /** Bytes of a source: from --dir, an explicit option, or downloaded. */
    private function source(string $key): string
    {
        [$file, $url] = self::SOURCES[$key];
        $explicit = match ($key) {
            'ine_municipios' => $this->option('ine'),
            'registradores' => $this->option('registradores'),
            default => null,
        };
        if (filled($explicit) && is_file((string) $explicit)) {
            return (string) file_get_contents((string) $explicit);
        }
        if (! filled($explicit) && filled($this->option('dir'))) {
            $local = rtrim((string) $this->option('dir'), '/').'/'.$file;
            if (! is_file($local)) {
                throw new RuntimeException("falta {$local}");
            }

            return (string) file_get_contents($local);
        }
        $urls = filled($explicit) ? [(string) $explicit] : ($key === 'ine_municipios'
            ? [sprintf($url, now()->format('y')), sprintf($url, now()->subYear()->format('y'))]
            : [$url]);
        foreach ($urls as $candidate) {
            $response = Http::timeout(90)->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; CLARTO-valoraciones)'])->get($candidate);
            if ($response->successful()) {
                $this->line("Descargado {$candidate}");

                return $response->body();
            }
        }

        throw new RuntimeException("{$key}: no se pudo descargar ".implode(' ni ', $urls));
    }

    /** @return array<string, string> INE code (5 digits) => official name */
    private function ineMunicipalities(string $bytes): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ine').'.xlsx';
        file_put_contents($tmp, $bytes);
        $reader = new XlsxReader;
        $reader->open($tmp);
        $out = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $c = array_map(fn ($v): string => trim((string) $v), $row->toArray());
                if (preg_match('/^\d{1,2}$/', $c[1] ?? '') && preg_match('/^\d{1,3}$/', $c[2] ?? '') && ($c[4] ?? '') !== '') {
                    $out[str_pad($c[1], 2, '0', STR_PAD_LEFT).str_pad($c[2], 3, '0', STR_PAD_LEFT)] = $c[4];
                }
            }
            break;
        }
        $reader->close();
        @unlink($tmp);
        if (count($out) < 8000) {
            throw new RuntimeException('lista de municipios del INE incompleta ('.count($out).')');
        }

        return $out;
    }

    /**
     * Latest quarter of table 35103500 (one sheet per quarter, «T2A2026»).
     *
     * @param  array<string, array<int, array<int, mixed>>>  $sheets
     * @return array{0: string, 1: list<array<string, mixed>>}
     */
    private function municipalSeries(array $sheets): array
    {
        $quarters = array_filter(array_keys($sheets), fn (string $n): bool => (bool) preg_match('/^T[1-4]A\d{4}$/', $n));
        usort($quarters, fn (string $a, string $b): int => substr($a, 3).$a[1] <=> substr($b, 3).$b[1]);
        $sheet = end($quarters) ?: throw new RuntimeException('35103500: sin hojas trimestrales');
        $rows = $sheets[$sheet];
        $period = substr($sheet, 3).'T'.$sheet[1];

        $header = null;
        foreach ($rows as $r => $cells) {
            if (in_array('Municipio', array_map(fn ($v): string => trim((string) $v), $cells), true)) {
                $header = $r;
                break;
            }
        }
        if ($header === null) {
            throw new RuntimeException("35103500 {$sheet}: sin cabecera");
        }
        $find = fn (string $text, int $r): array => array_keys(array_filter($rows[$r] ?? [], fn ($v): bool => str_contains(mb_strtolower((string) $v), $text)));
        $province = array_search('Provincia', array_map(fn ($v): string => trim((string) $v), $rows[$header]), true);
        $municipality = array_search('Municipio', array_map(fn ($v): string => trim((string) $v), $rows[$header]), true);
        [$new, $newCount] = $find('hasta cinco', $header + 1);
        [$old, $oldCount] = $find('con más de cinco', $header + 1);
        [$total, $totalCount] = $find('total', $header + 1);

        $out = [];
        $label = null;
        foreach ($rows as $r => $cells) {
            if ($r <= $header + 2 || trim((string) ($cells[$municipality] ?? '')) === '') {
                continue;
            }
            $label = trim((string) ($cells[$province] ?? '')) ?: $label;
            $num = fn (int $c): ?float => is_numeric($cells[$c] ?? null) ? (float) $cells[$c] : null;
            if ($num($total) === null) {
                continue;
            }
            $out[] = array_filter([
                'mivau' => trim((string) $cells[$municipality]),
                'provincia_mivau' => $label,
                'eur_m2' => $num($total), 'hasta_5_anos' => $num($new), 'mas_5_anos' => $num($old),
                'muestras' => (int) $num($totalCount), 'muestras_hasta_5_anos' => $num($newCount) === null ? null : (int) $num($newCount),
                'muestras_mas_5_anos' => $num($oldCount) === null ? null : (int) $num($oldCount),
            ], fn ($v): bool => $v !== null);
        }

        return [$period, $out];
    }

    /**
     * MIVAU names → INE codes: by name without articles and prepositions (also each part of «Alacant/Alicante»),
     * the province label of the table only to break a tie (the table mislabels some rows).
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, string>  $ine
     * @return array{0: array<string, array<string, mixed>>, 1: list<string>}
     */
    private function match(array $rows, array $ine): array
    {
        $index = [];
        foreach ($ine as $code => $name) {
            foreach (self::keys($name) as $key) {
                $index[$key][$code] = true;
            }
        }
        $matched = $unmatched = [];
        foreach ($rows as $row) {
            $codes = [];
            foreach (self::keys($row['mivau']) as $key) {
                $codes += $index[$key] ?? [];
            }
            $codes = array_map('strval', array_keys($codes));
            if (count($codes) > 1) {
                $province = Provinces::code((string) $row['provincia_mivau']);
                $codes = array_values(array_filter($codes, fn (string $c): bool => str_starts_with($c, (string) $province)));
            }
            $code = self::MUNICIPALITY_ALIASES[Catastro::normalize($row['mivau'])] ?? (count($codes) === 1 ? $codes[0] : null);
            if ($code === null) {
                $unmatched[] = $row['mivau'].' ('.$row['provincia_mivau'].', candidatos: '.(implode(', ', $codes) ?: 'ninguno').')';

                continue;
            }
            $matched[$code] = $row;
        }

        return [$matched, $unmatched];
    }

    /** @return list<string> */
    private static function keys(string $name): array
    {
        $keys = [];
        foreach ([$name, ...explode('/', $name)] as $part) {
            $tokens = array_diff(preg_split('/[\s\-\/]+/', Catastro::normalize($part)) ?: [], self::STOPWORDS, ['']);
            if ($tokens !== []) {
                $keys[] = implode(' ', $tokens);
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Province appraised value (35101000) and number of appraisals (35103000), latest quarter of the last sheet.
     *
     * @param  array<string, array<int, array<int, mixed>>>  $values
     * @param  array<string, array<int, array<int, mixed>>>  $counts
     * @return array<string, array<string, mixed>>
     */
    private function provinces(array $values, array $counts): array
    {
        $latest = function (array $sheets): array {
            $rows = end($sheets) ?: [];
            $years = $quarters = [];
            foreach ($rows as $cells) {
                foreach ($cells as $c => $v) {
                    if (is_string($v) && preg_match('/A[ñn]o (\d{4})/u', $v, $m)) {
                        $years[$c] = (int) $m[1];
                    } elseif (is_string($v) && preg_match('/^\s*([1-4])º\s*$/u', $v, $m)) {
                        $quarters[$c] = (int) $m[1];
                    }
                }
            }
            ksort($years);
            $periods = [];
            $year = null;
            foreach (array_keys($quarters) as $c) {
                foreach ($years as $yc => $y) {
                    $year = $yc <= $c ? $y : $year;
                }
                $periods[$c] = $year.'T'.$quarters[$c];
            }
            $national = collect($rows)->first(fn (array $cells): bool => trim((string) ($cells[1] ?? '')) === 'TOTAL NACIONAL') ?? [];
            $column = collect($periods)->keys()->filter(fn (int $c): bool => is_numeric($national[$c] ?? null))->sortBy(fn (int $c): string => $periods[$c])->last();
            if ($column === null) {
                throw new RuntimeException('tabla provincial sin columnas de trimestre');
            }
            $out = [];
            foreach ($rows as $cells) {
                $code = Provinces::code(self::mivauProvince((string) ($cells[1] ?? '')));
                if ($code !== null && is_numeric($cells[$column] ?? null)) {
                    $out[$code] = (float) $cells[$column];
                }
            }

            return [$periods[$column], $out];
        };
        [$period, $eur] = $latest($values);
        [$countPeriod, $n] = $latest($counts);

        $out = [];
        foreach (Provinces::ALL as $code => [$name]) {
            $code = (string) $code;
            $out[$code] = array_filter([
                'eur_m2' => $eur[$code] ?? null,
                'muestras' => $countPeriod === $period && isset($n[$code]) ? (int) $n[$code] : null,
                'periodo' => isset($eur[$code]) ? $period : null,
            ], fn ($v): bool => $v !== null);
        }

        return $out;
    }

    /** «Coruña (A)» → «A Coruña», «Madrid (Comunidad de)» → «Comunidad de Madrid». */
    private static function mivauProvince(string $name): string
    {
        $name = trim($name);

        return preg_match('/^(.*?)\s*\((.*?)\s*\)$/u', $name, $m) ? trim($m[2].' '.$m[1]) : $name;
    }

    /**
     * @param  list<array<string, mixed>>  $series
     * @return array<string, array{serie: string, indices: array<string, float>}>
     */
    private function ipv(array $series): array
    {
        $out = [];
        foreach (Provinces::CCAA as $code => $name) {
            $code = (string) $code;
            $found = collect($series)->first(fn (array $s): bool => Catastro::normalize((string) ($s['Nombre'] ?? '')) === Catastro::normalize($name.'. General. Índice.'));
            if ($found === null) {
                continue;
            }
            $indices = [];
            foreach ((array) $found['Data'] as $d) {
                if (isset($d['Anyo'], $d['FK_Periodo'], $d['Valor']) && $d['FK_Periodo'] >= 19 && $d['FK_Periodo'] <= 22) {
                    $indices[$d['Anyo'].'T'.($d['FK_Periodo'] - 18)] = (float) $d['Valor'];
                }
            }
            ksort($indices);
            $out[$code] = ['serie' => (string) $found['COD'], 'indices' => $indices];
        }
        if (count($out) < 19) {
            throw new RuntimeException('IPV: faltan comunidades ('.count($out).' de 19)');
        }

        return $out;
    }

    /** @return array<string, array{eur_m2: float, operaciones: int, periodo: string}> latest quarter by province */
    private function registradores(string $csv): array
    {
        $lines = preg_split('/\r?\n/', trim((string) preg_replace('/^\xEF\xBB\xBF/', '', $csv))) ?: [];
        $head = str_getcsv((string) array_shift($lines), ';');
        $out = [];
        foreach ($lines as $line) {
            $cells = str_getcsv($line, ';');
            $row = [];
            foreach ($head as $i => $name) {
                $row[$name] = $cells[$i] ?? null;
            }
            if (($row['geo'] ?? '') !== 'Provincia' || ! is_numeric($row['cod-prv'] ?? null)) {
                continue;
            }
            $code = str_pad((string) $row['cod-prv'], 2, '0', STR_PAD_LEFT);
            $period = $row['ano'].'T'.$row['trim'];
            if (! isset($out[$code]) || $period > $out[$code]['periodo']) {
                $out[$code] = ['eur_m2' => (float) str_replace(',', '.', (string) $row['viv-pm2']), 'operaciones' => (int) $row['viv-num'], 'periodo' => $period];
            }
        }
        if (count($out) < 50) {
            throw new RuntimeException('Registradores: CSV sin datos provinciales ('.count($out).')');
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, array<string, mixed>>  $matched
     * @param  array<string, string>  $ine
     * @param  array<string, array<string, mixed>>  $provinces
     * @param  array<string, mixed>  $ipv
     * @param  array<string, array<string, mixed>>  $registry
     * @return array<string, mixed>
     */
    private function merge(array $old, string $period, array $matched, array $ine, array $provinces, array $ipv, array $registry): array
    {
        $areas = [];
        foreach ((array) $old['ambitos'] as $code => $area) {
            $area['series'] = array_values(array_filter((array) ($area['series'] ?? []), fn (array $s): bool => $s['fuente'] !== 'mivau_tasado'));
            $areas[(string) $code] = $area;
        }
        foreach ($matched as $code => $row) {
            $areas[$code] ??= ['nombre' => $ine[$code], 'series' => []];
            $series = ['fuente' => 'mivau_tasado', ...array_diff_key($row, array_flip(['mivau', 'provincia_mivau'])), 'periodo' => $period, 'nombre_fuente' => $row['mivau']];
            array_unshift($areas[$code]['series'], $series);
        }
        $areas = array_filter($areas, fn (array $a): bool => $a['series'] !== [] || ! empty($a['codigos_postales']));
        ksort($areas, SORT_STRING);

        $context = [];
        foreach (Provinces::ALL as $code => [$name, , $ccaa]) {
            $code = (string) $code;
            $context[$code] = array_filter([
                'nombre' => $name,
                'ccaa' => $ccaa,
                'foral' => Provinces::foral($code) ?: null,
                'mivau' => ($provinces[$code] ?? []) ?: null,
                'registradores' => $registry[$code] ?? null,
            ], fn ($v): bool => $v !== null);
        }

        return [
            'version' => (string) ($this->option('etiqueta') ?: now()->format('Y-m-d')),
            'descripcion' => 'Instantánea fechada de precios públicos para el Estimador CLARTO, toda España. Generada con `php artisan clarto:valoraciones-datos` (MIVAU, INE, Registradores; ver docs/clarto/valoraciones.md) más las partes mantenidas a mano (series por código postal y sus excepciones, series pendientes de licencia, alias, anexos, calibración). En el cálculo solo entran las fuentes con uso «autorizado»; las «pendiente_licencia» solo con VALORACIONES_FUENTES_PENDIENTES_LICENCIA=true (pruebas internas) y quedan marcadas en el informe.',
            'datos' => ['consulta' => now()->toDateString(), 'mivau_periodo' => $period, 'municipios_mivau' => count($matched)],
            ...array_diff_key($old, array_flip(['version', 'descripcion', 'datos', 'ambitos', 'provincias', 'ipv'])),
            'fuentes' => [...(array) ($old['fuentes'] ?? []), ...self::SOURCE_META],
            'ambitos' => $areas,
            'provincias' => $context,
            'ipv' => $ipv,
        ];
    }

    /** Source descriptions added or refreshed in the snapshot. */
    private const SOURCE_META = [
        'mivau_tasado' => [
            'nombre' => 'MIVAU · Valor tasado medio de vivienda libre (municipios > 25.000 hab., tabla 35103500)',
            'tipo' => 'tasacion', 'superficie' => 'construida', 'uso' => 'autorizado',
            'licencia' => 'Reutilización autorizada (Ley 37/2007, RD 1495/2011). Origen de los datos: Ministerio de Vivienda y Agenda Urbana',
            'url' => 'https://cdn.transportes.gob.es/estadistica/BoletinOnline2/35103500.xls',
        ],
        'mivau_tasado_provincias' => [
            'nombre' => 'MIVAU · Valor tasado medio de vivienda libre por provincia (tablas 35101000 y 35103000)',
            'tipo' => 'tasacion', 'superficie' => 'construida', 'uso' => 'autorizado',
            'licencia' => 'Reutilización autorizada (Ley 37/2007, RD 1495/2011). Origen de los datos: Ministerio de Vivienda y Agenda Urbana',
            'url' => 'https://cdn.transportes.gob.es/estadistica/BoletinOnline2/35101000.xls',
        ],
        'ine_ipv' => [
            'nombre' => 'INE · Índice de Precios de Vivienda por comunidad autónoma (tabla 80270)',
            'tipo' => 'indice', 'superficie' => '—', 'uso' => 'autorizado',
            'licencia' => 'CC BY 4.0 (INE, aviso legal: reutilización citando la fuente)',
            'url' => 'https://servicios.ine.es/wstempus/js/ES/DATOS_TABLA/80270',
        ],
    ];

    /** Pretty JSON with each leaf object (a series, a province's figures) on one line. */
    private static function json(array $data): string
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);

        return (string) preg_replace_callback('/\{\n[^{}\[\]]*?\n\s*\}/', fn (array $m): string => (string) preg_replace('/\n\s*/', ' ', $m[0]), $json)."\n";
    }
}
