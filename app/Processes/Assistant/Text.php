<?php

declare(strict_types=1);

namespace App\Processes\Assistant;

use Illuminate\Support\Str;

/** Text helpers of the assistant: masking of personal identifiers and the search terms of a question. */
final class Text
{
    /** Order matters: e-mail and IBAN before the shorter identifiers they contain. */
    private const MASKS = [
        '/[\p{L}0-9._%+-]+@[\p{L}0-9-]+(?:\.[\p{L}0-9-]+)+/u' => '[correo]',
        '/\b[A-Z]{2}\d{2}(?:[ -]?[A-Z0-9]{4}){3,7}(?:[ -]?[A-Z0-9]{1,4})?\b/i' => '[IBAN]',
        '/\b[XYZ][ -]?\d{7}[ -]?[A-Z]\b/i' => '[NIE]',
        '/\b\d{8}[ -]?[A-Z]\b/i' => '[DNI]',
        '/\b[ABCDEFGHJNPQRSUVW][ -]?\d{7}[ -]?[0-9A-J]\b/i' => '[CIF]',
        '/(?<![\w+])(?:(?:\+|00)\d{2,3}[\s.-]?)?[6789](?:[\s.-]?\d){8}(?!\d)/' => '[teléfono]',
        '/\+\d{1,3}(?:[\s.-]?\d){6,12}(?!\d)/' => '[teléfono]',
    ];

    /** Words that never decide which procedure a question is about. */
    private const STOPWORDS = ['que', 'qué', 'como', 'cómo', 'cuando', 'cuándo', 'cuanto', 'cuánto', 'cuanta', 'cuánta', 'cuantos', 'cuántos', 'donde', 'dónde', 'quien', 'quién',
        'cual', 'cuál', 'cuales', 'para', 'por', 'porque', 'con', 'sin', 'los', 'las', 'una', 'unos', 'unas', 'del', 'al', 'el', 'la', 'lo', 'le', 'les', 'se', 'su', 'sus',
        'hay', 'hace', 'hacer', 'hago', 'tengo', 'tiene', 'tienen', 'tenemos', 'debo', 'debe', 'deben', 'puedo', 'puede', 'pueden', 'es', 'son', 'está', 'esta', 'este', 'esto',
        'estos', 'estas', 'ese', 'esa', 'eso', 'mi', 'mis', 'me', 'nos', 'yo', 'tu', 'tus', 'usted', 'ser', 'estar', 'haber', 'hacerlo', 'algo', 'alguien', 'cada', 'todo', 'todos',
        'muy', 'mas', 'más', 'pero', 'sobre', 'entre', 'hasta', 'desde', 'segun', 'según', 'tras', 'cual', 'vez', 'veces', 'caso', 'quiero', 'saber', 'necesito', 'ayuda',
        'procedimiento', 'proceso', 'clarto', 'pregunta', 'duda', 'podria', 'podriamos', 'podemos', 'hacemos', 'hago', 'tenemos', 'buenas', 'hola', 'gracias',
        'the', 'and', 'how', 'what', 'when', 'that'];

    public static function mask(string $text): string
    {
        return (string) preg_replace(array_keys(self::MASKS), array_values(self::MASKS), $text);
    }

    /** Masks the identifiers and, as whole words, the given names (people of the company). @param iterable<string> $names */
    public static function maskWithNames(string $text, iterable $names): string
    {
        $text = self::mask($text);
        foreach ($names as $name) {
            $name = trim((string) $name);
            if (mb_strlen($name) >= 4) {
                $text = (string) preg_replace('/(?<![\p{L}])'.preg_quote($name, '/').'(?![\p{L}])/iu', '[persona]', $text);
            }
        }

        return $text;
    }

    /** Lower case, without accents. */
    public static function fold(string $text): string
    {
        return Str::lower(Str::ascii($text));
    }

    /**
     * Significant terms as accent-free prefixes, so «comunicar» also finds «comunicación» and «viajero» «viajeros»
     * (unicode61 does not stem). @return list<string>
     */
    public static function terms(string $text, int $max = 12): array
    {
        $terms = [];
        $text = (string) preg_replace('/\[[^\]]*\]/u', ' ', $text);   // [DNI], [persona]… are not search terms
        foreach (preg_split('/[^\p{L}\p{N}]+/u', self::fold($text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (mb_strlen($word) < 3 || in_array($word, array_map(self::fold(...), self::STOPWORDS), true) || ctype_digit($word)) {
                continue;
            }
            $terms[] = mb_strlen($word) > 5 ? mb_substr($word, 0, max(5, mb_strlen($word) - 2)) : $word;
        }

        return array_slice(array_values(array_unique($terms)), 0, $max);
    }

    /** Share of the terms present (as prefixes) in the text. */
    public static function coverage(array $terms, string $text): float
    {
        if ($terms === []) {
            return 0.0;
        }
        $folded = ' '.preg_replace('/[^\p{L}\p{N}]+/u', ' ', self::fold($text));
        $found = count(array_filter($terms, fn (string $t): bool => str_contains($folded, ' '.$t)));

        return $found / count($terms);
    }

    /** Jaccard similarity of the terms of two titles (duplicate check). */
    public static function similarity(string $a, string $b): float
    {
        [$x, $y] = [self::terms($a, 30), self::terms($b, 30)];
        $union = count(array_unique([...$x, ...$y]));

        return $union === 0 ? 0.0 : count(array_intersect($x, $y)) / $union;
    }
}
