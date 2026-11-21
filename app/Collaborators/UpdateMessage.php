<?php

declare(strict_types=1);

namespace App\Collaborators;

use App\Filament\Colaboradores\Resources\MyPropertyResource;
use Illuminate\Support\Str;
use Liberu\RealEstate\Properties\Models\Property;

/**
 * The complete notice of a shared property update, in the collaborator's language: the e-mail and the WhatsApp text
 * carry everything (the panel link is only an extra). Never includes internal notes nor buyer/tenant personal data:
 * only the fields of the update the team filled in and the authorised data of the collaboration.
 */
final class UpdateMessage
{
    /** @return array{subject: string, email: string, whatsapp: list<string>} */
    public static function render(PropertyUpdate $update, Collaborator $collaborator, CollaboratorProperty $assignment): array
    {
        $l = $collaborator->locale ?: 'es';
        $t = fn (string $key, array $r = []): string => __('colaboradores.'.$key, $r, $l);
        $property = $update->property;
        $ref = $property->reference();
        $kind = $t('update_kinds.'.$update->kind);

        $lines = [];
        if ($update->kind === 'alta') {
            $lines = self::summary($property, $assignment, $t);
        } else {
            $lines[] = $t('mail.kind', ['kind' => $kind]);
            if ($update->kind === 'precio' && $update->price !== null) {
                $lines[] = $t('mail.price', ['v' => self::eur($update->price)]);
            }
            if ($update->availability) {
                $lines[] = $t('mail.availability', ['v' => $t('availability.'.$update->availability)]);
            }
            if ($update->visit_at || $update->visit_status) {
                $lines[] = $t('mail.visit', [
                    'date' => $update->visit_at?->timezone('Europe/Madrid')->format('d/m/Y H:i') ?? '—',
                    'status' => $update->visit_status ? $t('visit_statuses.'.$update->visit_status) : '—',
                ]);
            }
            foreach (['visit_result' => 'mail.visit_result', 'next_steps' => 'mail.next_steps', 'conditions' => 'mail.conditions'] as $field => $key) {
                if (filled($update->{$field})) {
                    $lines[] = $t($key, ['v' => trim((string) $update->{$field})]);
                }
            }
            if ($assignment->commercial_status) {
                $lines[] = $t('mail.commercial', ['v' => $t('commercial.'.$assignment->commercial_status)]);
            }
        }
        if (filled($update->attachments)) {
            $lines[] = $t('mail.attachments', ['v' => collect($update->attachments)->pluck('name')->implode(', ')]);
        }

        $place = self::place($property);
        $intro = $update->kind === 'alta' ? $t('mail.intro_alta', ['ref' => $ref, 'place' => $place]) : $t('mail.intro', ['ref' => $ref, 'place' => $place]);
        $body = trim((string) $update->body);
        $url = MyPropertyResource::getUrl('view', ['record' => $property->getKey()], panel: CollaboratorAccess::PANEL);
        $hello = $t('mail.hello', ['name' => $collaborator->firstName()]);

        $email = implode("\n", array_filter([
            $hello, '', $intro, '', ...array_map(fn (string $x): string => '- '.$x, $lines),
            $body !== '' ? "\n".$body : null, '',
            $t('mail.reply'), $t('mail.panel', ['url' => $url]), '', $t('mail.bye'), $t('mail.signature'),
        ], fn ($x) => $x !== null));

        $whatsapp = implode("\n", array_filter([
            $hello, '', $intro, '', ...array_map(fn (string $x): string => '• '.$x, $lines),
            $body !== '' ? "\n".$body : null, '',
            $t('mail.reply'), $t('mail.panel', ['url' => $url]), '', '*'.$t('mail.signature').'*',
        ], fn ($x) => $x !== null));

        return [
            'subject' => $t('mail.subject', ['kind' => $update->title ?: $kind, 'ref' => $ref]),
            'email' => $email,
            'whatsapp' => self::blocks($whatsapp),
        ];
    }

    /** @return list<string> */
    public static function summary(Property $property, CollaboratorProperty $assignment, \Closure $t): array
    {
        $title = trim((string) $property->title);
        $type = filled($property->property_type) ? __(Property::TYPES[$property->property_type] ?? (string) $property->property_type) : null;

        return array_values(array_filter([
            $title !== '' ? $t('mail.property_title', ['v' => $title]) : null,
            $type ? $t('mail.type', ['v' => $type]) : null,
            $t('mail.location', ['v' => self::place($property)]),
            $property->area_sqft !== null ? $t('mail.area', ['v' => rtrim(rtrim(number_format((float) $property->area_sqft, 2, ',', '.'), '0'), ',')]) : null,
            $property->bedrooms !== null ? $t('mail.rooms', ['v' => $property->bedrooms]) : null,
            $property->bathrooms !== null ? $t('mail.bathrooms', ['v' => $property->bathrooms]) : null,
            $t('mail.relation', ['v' => $t('relations.'.$assignment->relation)]),
            $assignment->authorised_price !== null ? $t('mail.price', ['v' => self::eur($assignment->authorised_price)]) : null,
            $assignment->availability ? $t('mail.availability', ['v' => $t('availability.'.$assignment->availability)]) : null,
            $assignment->commercial_status ? $t('mail.commercial', ['v' => $t('commercial.'.$assignment->commercial_status)]) : null,
        ]));
    }

    /** Municipality (and district / postal code) — never the exact address. */
    public static function place(Property $property): string
    {
        $property->loadMissing(['city', 'district']);

        return implode(', ', array_filter([$property->district?->name, $property->city?->name, $property->postal_code])) ?: '—';
    }

    public static function eur(mixed $value): string
    {
        return $value === null ? '—' : number_format((float) $value, 0, ',', '.').' €';
    }

    /**
     * WhatsApp blocks in order, split at paragraph boundaries when a message is too long for one bubble.
     *
     * @return list<string>
     */
    public static function blocks(string $text, int $max = 3500): array
    {
        $blocks = [];
        $current = '';
        foreach (preg_split("/\n{2,}/", $text) ?: [] as $paragraph) {
            if ($current !== '' && mb_strlen($current) + mb_strlen($paragraph) + 2 > $max) {
                $blocks[] = $current;
                $current = '';
            }
            $current = $current === '' ? $paragraph : $current."\n\n".$paragraph;
            while (mb_strlen($current) > $max) {
                $blocks[] = Str::substr($current, 0, $max);
                $current = Str::substr($current, $max);
            }
        }

        return $current === '' ? $blocks : [...$blocks, $current];
    }
}
