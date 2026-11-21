<?php

declare(strict_types=1);

namespace App\SearchRequests;

use Illuminate\Support\Str;

/**
 * The texts collaborators receive. Both channels carry the complete request (title, reference, location, budget,
 * surface, requirements, deadline, example links), so it can be understood without opening the panel; the panel
 * link goes at the end as an extra option. Owner's base text, in the collaborator's language («tú»), signed
 * «Equipo CLARTO». WhatsApp longer than one block goes as ordered blocks "(1/n)".
 */
final class SearchRequestMessages
{
    /**
     * @return array{subject: string, email: string, whatsapp: list<string>}
     */
    public static function compose(SearchRequest $request, string $kind, string $name, string $locale, string $link, ?string $note = null): array
    {
        $t = fn (string $key, array $replace = []): string => __('solicitudes.mensaje.'.$key, $replace, $locale);
        $ref = $request->reference;
        $deadline = $request->respond_by?->format('d/m/Y');

        $intro = match ($kind) {
            'actualizacion' => $t('intro_actualizacion', ['nombre' => $name, 'referencia' => $ref]),
            'aclaracion' => $t('intro_aclaracion', ['nombre' => $name, 'referencia' => $ref]),
            'recordatorio' => $t('intro_recordatorio', ['nombre' => $name, 'referencia' => $ref, 'fecha' => $deadline]),
            'plazo' => $t('intro_plazo', ['nombre' => $name, 'referencia' => $ref, 'fecha' => $deadline]),
            'cubierta' => $t('intro_cubierta', ['nombre' => $name, 'referencia' => $ref]),
            default => $t('intro', ['nombre' => $name]),
        };
        $question = match ($kind) {
            'aclaracion' => $t('pregunta_aclaracion'),
            'cubierta' => $t('gracias'),
            default => $t('pregunta'),
        };
        $noteBlock = filled($note) ? match ($kind) {
            'actualizacion' => $t('cambios')."\n".trim((string) $note),
            default => trim((string) $note),
        } : null;

        $paragraphs = array_values(array_filter([
            $intro,
            $noteBlock,
            self::summary($request, $locale),
            $question,
            $t('referencia', ['referencia' => $ref])."\n".($deadline && $kind !== 'cubierta' ? $t('limite', ['fecha' => $deadline])."\n" : '').$t('enlace', ['enlace' => $link]),
            (string) config('solicitudes.signature', 'Equipo CLARTO'),
        ]));

        $subject = '['.$ref.'] '.$t('asunto_'.$kind, ['titulo' => $request->title]);

        return [
            'subject' => Str::limit($subject, 240),
            'email' => implode("\n\n", $paragraphs),
            'whatsapp' => self::blocks($paragraphs, max(500, (int) config('solicitudes.whatsapp_block', 3500))),
        ];
    }

    /** The complete request, one line per filled field (empty fields are left out, never shown as blank). */
    public static function summary(SearchRequest $r, string $locale): string
    {
        $t = fn (string $key): string => __('solicitudes.campo.'.$key, [], $locale);
        $money = fn (?int $v): ?string => $v ? number_format($v, 0, ',', '.').' €' : null;
        $range = function (?string $min, ?string $max, string $unit) use ($locale): ?string {
            return match (true) {
                $min !== null && $max !== null => $min.' – '.$max.$unit,
                $min !== null => __('solicitudes.mensaje.desde', ['v' => $min.$unit], $locale),
                $max !== null => __('solicitudes.mensaje.hasta', ['v' => $max.$unit], $locale),
                default => null,
            };
        };

        $lines = [
            $r->title,
            $t('reference').': '.$r->reference,
            $t('operation').': '.__('solicitudes.operacion.'.$r->operation, [], $locale).' · '.$t('property_type').': '.__('solicitudes.tipo.'.$r->property_type, [], $locale),
            filled($r->municipality) ? $t('municipality').': '.$r->municipality : null,
            filled($r->zones) ? $t('zones').': '.$r->zones : null,
            filled($r->address) ? $t('address').': '.$r->address : null,
            ($v = $range($money($r->budget_min), $money($r->budget_max), '')) ? $t('budget').': '.$v : null,
            ($v = $range($r->surface_min ? (string) $r->surface_min : null, $r->surface_max ? (string) $r->surface_max : null, ' m²')) ? $t('surface').': '.$v : null,
            $r->bedrooms_min ? $t('bedrooms').': '.__('solicitudes.mensaje.o_mas', ['n' => $r->bedrooms_min], $locale) : null,
            filled($r->features) ? $t('features').': '.trim($r->features) : null,
            filled($r->must_have) ? $t('must_have').': '.trim($r->must_have) : null,
            filled($r->preferences) ? $t('preferences').': '.trim($r->preferences) : null,
            $r->needed_by ? $t('needed_by').': '.$r->needed_by->format('d/m/Y') : null,
            $r->respond_by ? $t('respond_by').': '.$r->respond_by->format('d/m/Y') : null,
            filled($r->description) ? $t('description').":\n".trim($r->description) : null,
            filled($r->example_links) ? $t('example_links').":\n".collect($r->example_links)->map(fn ($l) => '- '.(is_array($l) ? ($l['url'] ?? '') : $l))->filter(fn ($l) => $l !== '- ')->implode("\n") : null,
            filled($r->attachments) ? $t('attachments').': '.collect($r->attachments)->map(fn (string $p) => $r->attachment_names[$p] ?? basename($p))->implode(', ').' ('.__('solicitudes.mensaje.adjuntos_panel', [], $locale).')' : null,
        ];

        return implode("\n", array_filter($lines, fn ($l) => $l !== null && $l !== ''));
    }

    /**
     * Ordered WhatsApp blocks: whole paragraphs while they fit, "(i/n)" in front when there is more than one.
     *
     * @param  list<string>  $paragraphs
     * @return list<string>
     */
    public static function blocks(array $paragraphs, int $max): array
    {
        $max -= 8;   // room for "(10/12) "
        $pieces = [];
        foreach ($paragraphs as $p) {
            foreach (mb_str_split($p, $max) as $chunk) {   // a single paragraph longer than a block is cut
                $pieces[] = $chunk;
            }
        }
        $blocks = [''];
        foreach ($pieces as $piece) {
            $i = count($blocks) - 1;
            if ($blocks[$i] !== '' && mb_strlen($blocks[$i]) + 2 + mb_strlen($piece) > $max) {
                $blocks[] = '';
                $i++;
            }
            $blocks[$i] = $blocks[$i] === '' ? $piece : $blocks[$i]."\n\n".$piece;
        }
        $n = count($blocks);

        return $n === 1 ? $blocks : array_map(fn (string $b, int $i): string => '('.($i + 1).'/'.$n.') '.$b, $blocks, array_keys($blocks));
    }
}
