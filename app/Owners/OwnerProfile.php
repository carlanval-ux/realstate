<?php

declare(strict_types=1);

namespace App\Owners;

use App\Acquisition\GhlHandoff;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Liberu\RealEstate\Instructions\Models\Instruction;
use Liberu\RealEstate\Parties\Models\Party;
use Liberu\RealEstate\Valuations\Models\Valuation;

/**
 * Owner 360 profile, 1:1 with the owner's Party (name, e-mail and phone stay on the Party). Prefilled from the
 * GoHighLevel contact when an acquisition is won, completed by the team in Propietarios.
 *
 * Who is an owner: a Party of type vendor, or any Party with a profile (a buyer who later brings a property).
 */
final class OwnerProfile extends Model
{
    protected $table = 'clarto_owner_profiles';

    protected $guarded = ['id'];

    public const PERSON_TYPES = ['fisica' => 'propietarios.person_types.fisica', 'juridica' => 'propietarios.person_types.juridica'];

    public const CHANNELS = ['email', 'phone', 'whatsapp', 'sms', 'post'];

    public const LANGUAGES = ['es', 'ca', 'en', 'tr', 'fr', 'de'];

    /** Current version of the privacy notice / marketing consent text (the version accepted is stored with the date). */
    public const CONSENT_VERSION = '2026-11';

    /** Instruction statuses that count as an active mandate. */
    public const ACTIVE_MANDATE = ['draft', 'pending_approval', 'approved'];

    /** CRM001 consent channels and payment methods (owner fees are charged by direct debit or paid by transfer). */
    public const CONSENT_CHANNELS = ['email', 'sms', 'phone', 'post', 'any'];

    public const PAYMENT_METHODS = ['direct_debit', 'bank_transfer'];

    protected function casts(): array
    {
        return [
            'iban' => 'encrypted',
            'tags' => 'array',
            'postal_same_as_fiscal' => 'boolean',
            'birth_date' => 'date',
            'privacy_consent_at' => 'datetime',
            'marketing_consent_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $profile): void {
            foreach (['tax_id', 'representative_tax_id', 'iban'] as $field) {
                if (filled($profile->{$field})) {
                    $profile->{$field} = SpanishIds::normalize($profile->{$field});
                }
            }
            // A valid NIF/NIE/CIF sets its own type; passport and "other" are kept as chosen.
            $detected = filled($profile->tax_id) ? SpanishIds::taxIdType($profile->tax_id) : null;
            if ($detected !== null && in_array($profile->tax_id_type, [null, '', 'nif', 'nie', 'cif'], true)) {
                $profile->tax_id_type = $detected;
            } elseif (filled($profile->tax_id) && blank($profile->tax_id_type)) {
                $profile->tax_id_type = 'other';
            }
            foreach (['bic', 'nationality', 'fiscal_country', 'postal_country'] as $field) {
                if (filled($profile->{$field})) {
                    $profile->{$field} = strtoupper(trim((string) $profile->{$field}));
                }
            }
        });
        // History: which fields changed (never their values: some are sensitive).
        static::saved(function (self $profile): void {
            $fields = array_values(array_diff(array_keys($profile->getChanges()), ['updated_at', 'created_at']));
            if ($fields !== [] && ! $profile->wasRecentlyCreated && ($party = $profile->party) !== null) {
                self::log($party, 'profile_updated', ['fields' => $fields]);
            }
        });
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public static function canAccess(?Authenticatable $user = null): bool
    {
        $user ??= auth()->user();

        return $user !== null && method_exists($user, 'hasAdminAccess') && $user->hasAdminAccess();
    }

    /** NIF and IBAN in clear: administrators only; staff see them masked. */
    public static function canSeeSensitive(?Authenticatable $user = null): bool
    {
        $user ??= auth()->user();

        return $user !== null && (
            (method_exists($user, 'isAdmin') && $user->isAdmin())
            || (method_exists($user, 'hasRoleInAnyTeam') && $user->hasRoleInAnyTeam([(string) config('filament-shield.super_admin.name', 'super_admin'), 'admin']))
        );
    }

    public function displayTaxId(): ?string
    {
        return self::canSeeSensitive() ? $this->tax_id : SpanishIds::maskTaxId($this->tax_id);
    }

    public function displayIban(): ?string
    {
        return self::canSeeSensitive() ? SpanishIds::formatIban($this->iban) : SpanishIds::maskIban($this->iban);
    }

    /** Owner code, like CRM001's "CL-000001". */
    public function code(): ?string
    {
        return $this->exists ? 'PR-'.str_pad((string) $this->getKey(), 6, '0', STR_PAD_LEFT) : null;
    }

    /** Value of a GHL contact custom field by its annex-A key (id from config ghl.contact_fields or found by key), or null. */
    public static function ghlField(array $contact, string $name): ?string
    {
        return (new \App\Acquisition\GhlClient)->value($contact, 'contact', $name);
    }

    /**
     * Data still missing before a mandate can be signed and billed (translation keys of the fields).
     *
     * @return list<string>
     */
    public function missingFields(?Party $party = null): array
    {
        $party ??= $this->party;
        $missing = [];
        $required = [
            'tax_id' => $this->tax_id,
            'fiscal_address' => $this->fiscal_address && $this->fiscal_postal_code && $this->fiscal_city,
            'email' => $party?->email,
            'phone' => $party?->phone,
            'preferred_channel' => $this->preferred_channel,
            'privacy_consent_at' => $this->privacy_consent_at,
            'iban' => $this->iban,
        ];
        if ($this->person_type === 'juridica') {
            $required += ['legal_name' => $this->legal_name, 'representative_name' => $this->representative_name, 'representative_tax_id' => $this->representative_tax_id];
        } else {
            $required += ['first_name' => $this->first_name, 'last_name' => $this->last_name];
        }
        if ($this->payment_method === 'direct_debit') {
            $required += ['iban_holder' => $this->iban_holder];
        }
        foreach ($required as $field => $value) {
            if (blank($value)) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * Possible duplicates (CRM001 rule): parties of the team with the same id document, e-mail, phone (last nine
     * digits) or company name. Creating an owner that has any asks for confirmation.
     *
     * @return Collection<int, Party>
     */
    public static function duplicates(int|string|null $teamId, ?string $email, ?string $phone, ?string $taxId, ?string $legalName = null): Collection
    {
        $email = strtolower(trim((string) $email));
        $phone = SpanishIds::phoneKey($phone);
        $taxId = SpanishIds::normalize($taxId);
        $legalName = trim((string) $legalName);
        $team = fn () => Party::query()->where('team_id', $teamId);

        $ids = collect()
            ->merge($email === '' ? [] : $team()->whereRaw('lower(email) = ?', [$email])->pluck('id'))
            // ponytail: phones compared in PHP over the team's parties; add a normalised phone column if they reach tens of thousands.
            ->merge(strlen($phone) < 6 ? [] : $team()->whereNotNull('phone')->get(['id', 'phone'])->filter(fn (Party $p): bool => SpanishIds::phoneKey($p->phone) === $phone)->pluck('id'))
            ->merge($taxId === '' && $legalName === '' ? [] : self::query()->where('team_id', $teamId)
                ->where(fn ($q) => $q->when($taxId !== '', fn ($q) => $q->where('tax_id', $taxId))
                    ->when($legalName !== '', fn ($q) => $q->orWhereRaw('lower(legal_name) = ?', [mb_strtolower($legalName)])))
                ->pluck('party_id'));

        return $ids->isEmpty() ? collect() : $team()->whereIn('id', $ids->unique())->get();
    }

    /** The owner's profile, created (blank) the first time it is needed. */
    public static function for(Party $party, string $source = 'panel'): self
    {
        return self::query()->firstOrCreate(['party_id' => $party->getKey()], ['team_id' => $party->team_id, 'source' => $source]);
    }

    /**
     * Fills the blanks of the profile from a GoHighLevel contact; never overwrites what the team has entered.
     *
     * @param  array<string, mixed>  $contact
     */
    public function prefillFromGhl(array $contact): self
    {
        $taxId = self::ghlField($contact, 'nif');
        $taxIdType = SpanishIds::taxIdType($taxId);
        $company = trim((string) ($contact['companyName'] ?? ''));
        $language = strtolower(substr((string) (self::ghlField($contact, 'idioma') ?? ''), 0, 2));
        // GHL dropdown labels («WhatsApp», «Teléfono», «Correo electrónico») or our codes (L-10).
        $channel = ['whatsapp' => 'whatsapp', 'teléfono' => 'phone', 'telefono' => 'phone', 'correo electrónico' => 'email', 'correo electronico' => 'email']
            [mb_strtolower(trim((string) (self::ghlField($contact, 'canal_preferido') ?? '')))] ?? strtolower((string) (self::ghlField($contact, 'canal_preferido') ?? ''));
        // Consents recorded with the valuation request (L-10): processing (privacy) and commercial, with date and version.
        $date = fn (?string $v): ?\Illuminate\Support\Carbon => filled($v) ? rescue(fn () => \Illuminate\Support\Carbon::parse((string) $v), null, false) : null;
        $mailOk = self::ghlField($contact, 'consentimiento_comercial_email') === 'Sí';
        $whatsAppOk = self::ghlField($contact, 'consentimiento_comercial_whatsapp') === 'Sí';
        $marketingAt = $mailOk ? $date(self::ghlField($contact, 'consentimiento_comercial_email_fecha')) : ($whatsAppOk ? $date(self::ghlField($contact, 'consentimiento_comercial_whatsapp_fecha')) : null);
        $postalCode = trim((string) ($contact['postalCode'] ?? ''));
        $country = strtoupper(substr(trim((string) ($contact['country'] ?? '')), 0, 2));
        $values = [
            'person_type' => $taxIdType === 'cif' || ($company !== '' && blank($contact['firstName'] ?? null)) ? 'juridica' : null,
            'first_name' => trim((string) ($contact['firstName'] ?? '')),
            'last_name' => trim((string) ($contact['lastName'] ?? '')),
            'legal_name' => $company,
            'tax_id_type' => $taxIdType,
            'tax_id' => $taxIdType !== null ? SpanishIds::normalize($taxId) : null,   // an invalid NIF is not copied
            'fiscal_address' => trim((string) ($contact['address1'] ?? '')),
            'fiscal_postal_code' => $postalCode,
            'fiscal_city' => trim((string) ($contact['city'] ?? '')),
            // Spain: the province follows from the postcode (GHL's free-text "state" only abroad).
            'fiscal_province' => in_array($country, ['', 'ES'], true) ? SpanishIds::provinceForPostalCode($postalCode) : trim((string) ($contact['state'] ?? '')),
            'fiscal_country' => $country,
            'preferred_language' => in_array($language, self::LANGUAGES, true) ? $language : null,
            'preferred_channel' => in_array($channel, self::CHANNELS, true) ? $channel : null,
            'privacy_consent_at' => $date(self::ghlField($contact, 'consentimiento_tratamiento_fecha')),
            'privacy_consent_version' => self::ghlField($contact, 'consentimiento_tratamiento_version'),
            'consent_source' => self::ghlField($contact, 'consentimiento_origen'),
            'marketing_consent_at' => $marketingAt,
            'marketing_consent_version' => $marketingAt !== null ? self::ghlField($contact, 'consentimiento_comercial_version') : null,
            // ponytail: CONSENT_CHANNELS has no «whatsapp»: e-mail consent is the channel, WhatsApp is kept in the evidence text
            'marketing_consent_channel' => $mailOk ? 'email' : null,
            'consent_evidence' => $marketingAt !== null ? mb_substr(trim(($whatsAppOk ? "WhatsApp: Sí\n" : '').self::ghlField($contact, 'consentimiento_comercial_texto')), 0, 2000) ?: null : null,
        ];
        foreach ($values as $field => $value) {
            if (filled($value) && (blank($this->{$field}) || ($field === 'fiscal_country' && blank($this->fiscal_address)))) {
                $this->{$field} = $value;
            }
        }
        if ($taxId !== null && $taxIdType === null) {
            $this->notes = trim($this->notes."\n".__('propietarios.ghl_invalid_tax_id', ['value' => $taxId]));
        }
        $tags = array_values(array_unique(array_merge((array) $this->tags, array_map('strval', (array) ($contact['tags'] ?? [])))));
        $this->tags = $tags === [] ? null : $tags;
        $this->save();

        return $this;
    }

    /** @return Collection<int, int> ids of the properties this owner brought (mandates, valuations, GHL files) */
    public static function propertyIds(Party $party): Collection
    {
        $id = $party->getKey();

        return Instruction::query()->where('party_id', $id)->whereNotNull('property_id')->pluck('property_id')
            ->merge(Valuation::query()->where('party_id', $id)->whereNotNull('property_id')->pluck('property_id'))
            ->merge(GhlHandoff::query()->where('party_id', $id)->whereNotNull('property_id')->pluck('property_id'))
            ->map(fn ($v): int => (int) $v)->unique()->values();
    }

    /** @param array<string, mixed> $properties */
    public static function log(Party $party, string $event, array $properties = []): void
    {
        activity('propietarios')->performedOn($party)->causedBy(auth()->user())->event($event)->withProperties($properties)->log($event);
    }
}
