<?php

declare(strict_types=1);

namespace App\Owners;

/** Spanish tax ids (NIF, NIE, CIF) and IBAN: normalisation, checksum validation and masked display. */
final class SpanishIds
{
    private const DNI_LETTERS = 'TRWAGMYFPDXBNJZSQVHLCKE';

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
