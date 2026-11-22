<?php

declare(strict_types=1);

namespace App\Collaborators\Import;

use App\Collaborators\Collaborator;
use Illuminate\Support\Str;

/**
 * White label: imported texts that can reach clarto.es (title, description, translations, features) lose every trace
 * of the collaborator — its name and brand (also the one in its web address), phone numbers, e-mails, web addresses and
 * «ref. XXX» mentions. clean() returns what it removed so the importer can keep the original for staff and flag the
 * property for review.
 */
final class PublicTextCleaner
{
    /** @param list<string> $brands */
    private function __construct(private readonly array $brands, private readonly ?string $reference) {}

    public static function for(Collaborator $collaborator, ?CollaboratorImport $import, ?string $reference = null, array $urls = []): self
    {
        $brands = [];
        foreach ([$collaborator->name, $collaborator->legal_name] as $name) {
            $name = trim((string) $name);
            if (mb_strlen($name) >= 3) {
                $brands[] = $name;
                $core = trim((string) preg_replace('/[\s,.]+(s\.?\s?l\.?\s?u?\.?|s\.?\s?a\.?\s?u?\.?|s\.?\s?c\.?|c\.?\s?b\.?|slu|sl|sa)$/iu', '', $name));
                if ($core !== $name && mb_strlen($core) >= 4) {
                    $brands[] = $core;
                }
            }
        }
        // The brand inside its web address: «inmo-ficticia.es» → «inmo-ficticia», «inmo ficticia», «inmoficticia».
        foreach ([$import?->web_url, $import?->feed_url, ...$urls] as $url) {
            $host = Str::lower((string) parse_url((string) $url, PHP_URL_HOST));
            $labels = array_values(array_filter(explode('.', (string) preg_replace('/^(www|web|feeds?|img|images?|static|cdn|m)\./', '', $host))));
            $label = count($labels) >= 2 ? $labels[count($labels) - 2] : null;
            if ($label !== null && mb_strlen($label) >= 4) {
                array_push($brands, $label, str_replace('-', ' ', $label), str_replace('-', '', $label));
            }
        }
        usort($brands, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));   // longest first

        return new self(array_values(array_unique($brands)), filled($reference) ? trim((string) $reference) : null);
    }

    /** @return array{0: ?string, 1: list<string>} clean text (null if nothing is left) and the removed pieces */
    public function clean(?string $text): array
    {
        if ($text === null || trim($text) === '') {
            return [null, []];
        }
        // Contact traces: the whole sentence / title segment that carries one goes («Llame al 600…», «… | Inmo X»).
        $contact = [
            '/[\w.+-]+@[\w-]+(\.[\w-]+)+/u',                                                                  // e-mails
            '~\b(?:https?://|www\.)\S+~iu',                                                                    // web addresses
            '~\b[a-z0-9][a-z0-9-]*\.(?:es|com|net|org|eu|info|biz|cat|gal|eus|io|co|online|site|house|homes|realty|test)\b(?:/\S*)?~iu',
            '/(?<![\d.,])(?:\+?34[\s.-]?)?[6789]\d{2}(?:(?:[\s.-]?\d{3}){2}|(?:[\s.-]?\d{2}){3})(?![\d.,]*\s?(?:€|eur|m2|m²))/iu',   // phones
            '/\[…\]/u',                                                                                         // phone / e-mail already blanked
        ];
        foreach ($this->brands as $brand) {
            $contact[] = '/(?<![\w])'.str_replace('\ ', '[\s-]+', preg_quote($brand, '/')).'(?![\w])/iu';
        }
        // References: cut out in place («Piso en venta, ref. A-12.» → «Piso en venta.»).
        $inline = [
            '/[(\[]?\s*\b(?:ref(?:erencia)?|reference|c[oó]d(?:igo)?)\b\.?\s*(?:n[ºo°]\.?\s*)?[:#]?\s*[a-z0-9][a-z0-9\/_.-]*\d[a-z0-9\/_-]*\s*[)\]]?/iu',
        ];
        if ($this->reference !== null && mb_strlen($this->reference) >= 3) {
            $inline[] = '/(?<![\w-])'.preg_quote($this->reference, '/').'(?![\w-])/iu';
        }

        $removed = [];
        $parts = preg_split('/((?<=[.!?])(?<!\b(?:ref|tel|tlf|avda|sra|sr|dr|aprox|num|núm|nº)\.)\s+|\n+|\s+[|·•–—-]\s+)/iu', trim($text), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $kept = [];
        for ($i = 0; $i < count($parts); $i += 2) {
            $segment = $parts[$i];
            $hit = false;
            foreach ($contact as $pattern) {
                if (preg_match_all($pattern, $segment, $m)) {
                    array_push($removed, ...array_map('trim', $m[0]));
                    $hit = true;
                }
            }
            if ($hit) {
                continue;
            }
            foreach ($inline as $pattern) {
                $segment = (string) preg_replace_callback($pattern, function (array $m) use (&$removed): string {
                    $removed[] = trim($m[0]);

                    return '';
                }, $segment);
            }
            $kept[] = [$segment, $parts[$i + 1] ?? ''];
        }
        if ($removed === []) {
            return [$text, []];
        }
        $out = '';
        foreach ($kept as $k => [$segment, $delimiter]) {
            $out .= $segment.($k < count($kept) - 1 ? (str_contains($delimiter, "\n") ? $delimiter : (trim($delimiter) === '' ? ' ' : $delimiter)) : '');
        }
        $out = (string) preg_replace(['/\(\s*\)|\[\s*\]/u', '/[ \t]+([,.;:])/u', '/([,;:])\s*(?=[.;:])/u', '/\.{2,}/u', '/[ \t]{2,}/u', '/^[\s,.;:|·•–—-]+|[\s,;:|·•–—-]+$/mu'],
            ['', '$1', '', '.', ' ', ''], $out);
        $out = trim($out);

        return [$out === '' || ! preg_match('/\p{L}{3}/u', $out) ? null : $out, array_values(array_unique(array_filter($removed)))];
    }


    /** @return list<string> lowercase brand words, for the photo metadata check */
    public function brands(): array
    {
        return array_values(array_unique(array_map(fn (string $b): string => Str::lower(Str::ascii($b)), $this->brands)));
    }
}
