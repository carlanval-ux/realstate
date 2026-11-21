<?php

declare(strict_types=1);

namespace App\Valuations;

/**
 * The 50 provinces plus Ceuta and Melilla by INE code: official name, the name the State Catastro (OVC) uses, the
 * autonomous community (INE CODAUTO) and whether the cadastre is foral (Álava, Gipuzkoa, Bizkaia and Navarra keep their
 * own cadastres, not served by the OVC). A municipality's INE code starts with its province code; so does a postcode.
 */
final class Provinces
{
    /** code => [name, Catastro name (null = foral, not in the OVC), CCAA code] */
    public const ALL = [
        '01' => ['Araba/Álava', null, '16'], '02' => ['Albacete', 'ALBACETE', '08'], '03' => ['Alicante/Alacant', 'ALACANT', '10'],
        '04' => ['Almería', 'ALMERIA', '01'], '05' => ['Ávila', 'AVILA', '07'], '06' => ['Badajoz', 'BADAJOZ', '11'],
        '07' => ['Illes Balears', 'ILLES BALEARS', '04'], '08' => ['Barcelona', 'BARCELONA', '09'], '09' => ['Burgos', 'BURGOS', '07'],
        '10' => ['Cáceres', 'CACERES', '11'], '11' => ['Cádiz', 'CADIZ', '01'], '12' => ['Castellón/Castelló', 'CASTELLO', '10'],
        '13' => ['Ciudad Real', 'CIUDAD REAL', '08'], '14' => ['Córdoba', 'CORDOBA', '01'], '15' => ['A Coruña', 'A CORUÑA', '12'],
        '16' => ['Cuenca', 'CUENCA', '08'], '17' => ['Girona', 'GIRONA', '09'], '18' => ['Granada', 'GRANADA', '01'],
        '19' => ['Guadalajara', 'GUADALAJARA', '08'], '20' => ['Gipuzkoa', null, '16'], '21' => ['Huelva', 'HUELVA', '01'],
        '22' => ['Huesca', 'HUESCA', '02'], '23' => ['Jaén', 'JAEN', '01'], '24' => ['León', 'LEON', '07'],
        '25' => ['Lleida', 'LLEIDA', '09'], '26' => ['La Rioja', 'LA RIOJA', '17'], '27' => ['Lugo', 'LUGO', '12'],
        '28' => ['Madrid', 'MADRID', '13'], '29' => ['Málaga', 'MALAGA', '01'], '30' => ['Murcia', 'MURCIA', '14'],
        '31' => ['Navarra', null, '15'], '32' => ['Ourense', 'OURENSE', '12'], '33' => ['Asturias', 'ASTURIAS', '03'],
        '34' => ['Palencia', 'PALENCIA', '07'], '35' => ['Las Palmas', 'LAS PALMAS', '05'], '36' => ['Pontevedra', 'PONTEVEDRA', '12'],
        '37' => ['Salamanca', 'SALAMANCA', '07'], '38' => ['Santa Cruz de Tenerife', 'S.C. TENERIFE', '05'], '39' => ['Cantabria', 'CANTABRIA', '06'],
        '40' => ['Segovia', 'SEGOVIA', '07'], '41' => ['Sevilla', 'SEVILLA', '01'], '42' => ['Soria', 'SORIA', '07'],
        '43' => ['Tarragona', 'TARRAGONA', '09'], '44' => ['Teruel', 'TERUEL', '02'], '45' => ['Toledo', 'TOLEDO', '08'],
        '46' => ['Valencia/València', 'VALENCIA', '10'], '47' => ['Valladolid', 'VALLADOLID', '07'], '48' => ['Bizkaia', null, '16'],
        '49' => ['Zamora', 'ZAMORA', '07'], '50' => ['Zaragoza', 'ZARAGOZA', '02'], '51' => ['Ceuta', 'CEUTA', '18'], '52' => ['Melilla', 'MELILLA', '19'],
    ];

    /** Other spellings (normalised) besides each official name, its «/» parts and the Catastro name. */
    private const ALIASES = [
        'ALAVA' => '01', 'ARABA' => '01', 'ALICANTE' => '03', 'ALACANT' => '03', 'BALEARES' => '07', 'ISLAS BALEARES' => '07',
        'BALEARS' => '07', 'CASTELLON' => '12', 'CASTELLO' => '12', 'CORUNA' => '15', 'LA CORUNA' => '15', 'GERONA' => '17',
        'GUIPUZCOA' => '20', 'LERIDA' => '25', 'RIOJA' => '26', 'NAFARROA' => '31', 'COMUNIDAD FORAL DE NAVARRA' => '31',
        'ORENSE' => '32', 'PRINCIPADO DE ASTURIAS' => '33', 'PALMAS' => '35', 'TENERIFE' => '38', 'S C TENERIFE' => '38',
        'SANTA CRUZ TENERIFE' => '38', 'VIZCAYA' => '48', 'COMUNIDAD DE MADRID' => '28', 'REGION DE MURCIA' => '30',
    ];

    /** Autonomous communities (INE CODAUTO) as the INE names them in the IPV (table 80270). */
    public const CCAA = [
        '01' => 'Andalucía', '02' => 'Aragón', '03' => 'Asturias, Principado de', '04' => 'Balears, Illes', '05' => 'Canarias',
        '06' => 'Cantabria', '07' => 'Castilla y León', '08' => 'Castilla - La Mancha', '09' => 'Cataluña', '10' => 'Comunitat Valenciana',
        '11' => 'Extremadura', '12' => 'Galicia', '13' => 'Madrid, Comunidad de', '14' => 'Murcia, Región de', '15' => 'Navarra, Comunidad Foral de',
        '16' => 'País Vasco', '17' => 'Rioja, La', '18' => 'Ceuta', '19' => 'Melilla',
    ];

    /** @var array<string, string>|null */
    private static ?array $index = null;

    /** INE code of a province from its name in any usual spelling, its two-digit code, or a postcode. */
    public static function code(?string $nameOrCode): ?string
    {
        $text = trim((string) $nameOrCode);
        if (preg_match('/^\d{1,2}$/', $text) || preg_match('/^\d{5}$/', $text)) {
            $code = strlen($text) === 5 ? substr($text, 0, 2) : str_pad($text, 2, '0', STR_PAD_LEFT);

            return isset(self::ALL[$code]) ? $code : null;
        }
        if (self::$index === null) {
            self::$index = self::ALIASES;
            foreach (self::ALL as $code => [$name, $catastro]) {
                foreach ([$name, ...explode('/', $name), (string) $catastro] as $spelling) {
                    if (($key = Catastro::normalize($spelling)) !== '') {
                        self::$index[$key] ??= (string) $code;
                    }
                }
            }
        }
        $key = Catastro::normalize($text);

        return self::$index[$key] ?? self::$index[(string) preg_replace('/^(PROVINCIA DE|LA|LAS|EL|A)\s+/', '', $key)] ?? null;
    }

    public static function name(?string $code): ?string
    {
        return self::ALL[(string) $code][0] ?? null;
    }

    /** Province name for the OVC (null for the foral territories and unknown codes). */
    public static function catastroName(?string $code): ?string
    {
        return self::ALL[(string) $code][1] ?? null;
    }

    public static function ccaa(?string $code): ?string
    {
        return self::ALL[(string) $code][2] ?? null;
    }

    /** Álava, Gipuzkoa, Bizkaia, Navarra: foral cadastre, not in the State Catastro's web services. */
    public static function foral(?string $code): bool
    {
        return isset(self::ALL[(string) $code]) && self::ALL[(string) $code][1] === null;
    }
}
