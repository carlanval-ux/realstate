<?php

declare(strict_types=1);

namespace App\Owners;

/**
 * Identity and contact data as validated by the CRM001 customer record (DEALER): NIF/NIE/CIF (with control
 * digit), passport and other ids, e-mail, phone, IBAN/BIC, Spanish postcode ↔ province. Normalisation and
 * masked display.
 */
final class SpanishIds
{
    public const ID_TYPES = ['nif', 'nie', 'cif', 'passport', 'other'];

    /** INE province code (= first two digits of the postcode) → name. */
    public const PROVINCES = [
        '01' => 'Araba/Álava', '02' => 'Albacete', '03' => 'Alicante/Alacant', '04' => 'Almería', '05' => 'Ávila', '06' => 'Badajoz',
        '07' => 'Illes Balears', '08' => 'Barcelona', '09' => 'Burgos', '10' => 'Cáceres', '11' => 'Cádiz', '12' => 'Castellón/Castelló',
        '13' => 'Ciudad Real', '14' => 'Córdoba', '15' => 'A Coruña', '16' => 'Cuenca', '17' => 'Girona', '18' => 'Granada',
        '19' => 'Guadalajara', '20' => 'Gipuzkoa', '21' => 'Huelva', '22' => 'Huesca', '23' => 'Jaén', '24' => 'León', '25' => 'Lleida',
        '26' => 'La Rioja', '27' => 'Lugo', '28' => 'Madrid', '29' => 'Málaga', '30' => 'Murcia', '31' => 'Navarra', '32' => 'Ourense',
        '33' => 'Asturias', '34' => 'Palencia', '35' => 'Las Palmas', '36' => 'Pontevedra', '37' => 'Salamanca', '38' => 'Santa Cruz de Tenerife',
        '39' => 'Cantabria', '40' => 'Segovia', '41' => 'Sevilla', '42' => 'Soria', '43' => 'Tarragona', '44' => 'Teruel', '45' => 'Toledo',
        '46' => 'Valencia/València', '47' => 'Valladolid', '48' => 'Bizkaia', '49' => 'Zamora', '50' => 'Zaragoza', '51' => 'Ceuta', '52' => 'Melilla',
    ];

    private const DNI_LETTERS = 'TRWAGMYFPDXBNJZSQVHLCKE';

    /** Whether $value is a valid id of $type (nif, nie, cif: control digit; passport: 5–20; other: 2–40 characters). */
    public static function isValidId(?string $type, ?string $value): bool
    {
        $id = self::normalize($value);

        return match ($type) {
            'nif', 'nie', 'cif' => self::taxIdType($id) === $type,
            'passport' => (bool) preg_match('/^[A-Z0-9]{5,20}$/', $id),
            'other' => strlen($id) >= 2 && strlen($id) <= 40,
            default => false,
        };
    }

    /** CRM001 e-mail rule (stored lower case). */
    public static function isValidEmail(?string $value): bool
    {
        return (bool) preg_match('/^[^@\s]{1,64}@[^@\s]{1,190}\.[a-z]{2,}$/', strtolower(trim((string) $value)));
    }

    /** CRM001 phone rule: 6–15 digits, optional leading "+", spaces, dots, dashes and brackets ignored. */
    public static function isValidPhone(?string $value): bool
    {
        return (bool) preg_match('/^\+?\d{6,15}$/', (string) preg_replace('/[\s.\-()]/', '', (string) $value));
    }

    public static function isValidBic(?string $value): bool
    {
        return (bool) preg_match('/^[A-Z0-9]{8}([A-Z0-9]{3})?$/', strtoupper(trim((string) $value)));
    }

    /** Last nine digits of a phone: "+34 600 00 00 01" and "600000001" are the same phone (duplicate detection). */
    public static function phoneKey(?string $phone): string
    {
        $digits = (string) preg_replace('/\D/', '', (string) $phone);

        return strlen($digits) > 9 ? substr($digits, -9) : $digits;
    }

    /** Province of a Spanish postcode (01000–52999), or null. */
    public static function provinceForPostalCode(?string $postalCode): ?string
    {
        $cp = trim((string) $postalCode);

        return preg_match('/^\d{5}$/', $cp) ? (self::PROVINCES[substr($cp, 0, 2)] ?? null) : null;
    }

    public static function normalize(?string $value): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $value));
    }

    /** 'nif' | 'nie' | 'cif' when the id is valid (checksum included), null otherwise. */
    public static function taxIdType(?string $value): ?string
    {
        $id = self::normalize($value);

        if (preg_match('/^(\d{8})([A-Z])$/', $id, $m)) {
            return self::DNI_LETTERS[(int) $m[1] % 23] === $m[2] ? 'nif' : null;
        }
        if (preg_match('/^[KLM](\d{7})([A-Z])$/', $id, $m)) {
            return self::DNI_LETTERS[(int) $m[1] % 23] === $m[2] ? 'nif' : null;
        }
        if (preg_match('/^([XYZ])(\d{7})([A-Z])$/', $id, $m)) {
            return self::DNI_LETTERS[(int) (strpos('XYZ', $m[1]).$m[2]) % 23] === $m[3] ? 'nie' : null;
        }
        if (preg_match('/^([ABCDEFGHJNPQRSUVW])(\d{7})([0-9A-J])$/', $id, $m)) {
            $sum = 0;
            foreach (str_split($m[2]) as $i => $digit) {
                $n = (int) $digit;
                $sum += $i % 2 === 0 ? intdiv($n * 2, 10) + ($n * 2) % 10 : $n;
            }
            $digit = (10 - $sum % 10) % 10;
            $letter = 'JABCDEFGHI'[$digit];
            $valid = match (true) {
                str_contains('PQRSNW', $m[1]) => $m[3] === $letter,
                str_contains('ABEH', $m[1]) => $m[3] === (string) $digit,
                default => $m[3] === $letter || $m[3] === (string) $digit,
            };

            return $valid ? 'cif' : null;
        }

        return null;
    }

    public static function isValidIban(?string $value): bool
    {
        $iban = self::normalize($value);
        if (! preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban) || (str_starts_with($iban, 'ES') && strlen($iban) !== 24)) {
            return false;
        }

        $remainder = 0;
        foreach (str_split(substr($iban, 4).substr($iban, 0, 4)) as $char) {
            $chunk = ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
            foreach (str_split($chunk) as $d) {
                $remainder = ($remainder * 10 + (int) $d) % 97;
            }
        }

        return $remainder === 1;
    }

    /** "•••••678Z": only the last four characters. */
    public static function maskTaxId(?string $value): ?string
    {
        $id = self::normalize($value);

        return $id === '' ? null : str_repeat('•', max(strlen($id) - 4, 1)).substr($id, -4);
    }

    /** "ES•• •••• … 1234": country code and last four digits only. */
    public static function maskIban(?string $value): ?string
    {
        $iban = self::normalize($value);
        if ($iban === '') {
            return null;
        }

        return trim(chunk_split(substr($iban, 0, 2).str_repeat('•', max(strlen($iban) - 6, 2)).substr($iban, -4), 4, ' '));
    }

    public static function formatIban(?string $value): ?string
    {
        $iban = self::normalize($value);

        return $iban === '' ? null : trim(chunk_split($iban, 4, ' '));
    }
}
