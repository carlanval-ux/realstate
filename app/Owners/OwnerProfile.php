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

    /**
     * Fields still to contrast with the "ficha 360" of the billing tools (structure, validations and permissions
     * pending from the orchestrator). See docs/clarto/propietarios.md.
     */
    public const PENDING_BILLING_CONTRAST = [
        'person_type', 'tax_id', 'representative_name', 'representative_tax_id', 'fiscal_address', 'postal_address',
        'preferred_channel', 'preferred_language', 'privacy_consent_version', 'marketing_consent_version', 'iban', 'iban_holder', 'tags',
    ];

    protected function casts(): array
    {
        return [
            'iban' => 'encrypted',
            'tags' => 'array',
            'postal_same_as_fiscal' => 'boolean',
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
        foreach ($required as $field => $value) {
            if (blank($value)) {
                $missing[] = $field;
            }
        }

        return $missing;
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
        $custom = [];
        foreach ((array) ($contact['customFields'] ?? $contact['customField'] ?? []) as $field) {
            $value = $field['value'] ?? $field['fieldValue'] ?? $field['field_value'] ?? null;
            $custom[(string) ($field['id'] ?? '')] = is_array($value) ? implode(', ', $value) : (string) $value;
        }
        $customField = fn (string $name): ?string => filled($id = config('ghl.contact_fields.'.$name)) && filled($custom[$id] ?? null) ? trim($custom[$id]) : null;

        $taxId = $customField('nif');
        $taxIdType = SpanishIds::taxIdType($taxId);
        $company = trim((string) ($contact['companyName'] ?? ''));
        $language = strtolower(substr((string) ($customField('idioma') ?? ''), 0, 2));
        $channel = strtolower((string) ($customField('canal_preferido') ?? ''));
        $values = [
            'person_type' => $taxIdType === 'cif' || ($company !== '' && blank($contact['firstName'] ?? null)) ? 'juridica' : null,
            'first_name' => trim((string) ($contact['firstName'] ?? '')),
            'last_name' => trim((string) ($contact['lastName'] ?? '')),
            'legal_name' => $company,
            'tax_id' => $taxIdType !== null ? SpanishIds::normalize($taxId) : null,   // an invalid NIF is not copied
            'fiscal_address' => trim((string) ($contact['address1'] ?? '')),
            'fiscal_postal_code' => trim((string) ($contact['postalCode'] ?? '')),
            'fiscal_city' => trim((string) ($contact['city'] ?? '')),
            'fiscal_province' => trim((string) ($contact['state'] ?? '')),
            'fiscal_country' => strtoupper(substr(trim((string) ($contact['country'] ?? '')), 0, 2)),
            'preferred_language' => in_array($language, self::LANGUAGES, true) ? $language : null,
            'preferred_channel' => in_array($channel, self::CHANNELS, true) ? $channel : null,
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
