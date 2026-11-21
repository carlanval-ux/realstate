<?php

declare(strict_types=1);

namespace App\Acquisition;

use App\Owners\OwnerProfile;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\RealEstate\Instructions\Application\CreateInstruction;
use Liberu\RealEstate\Instructions\Models\Instruction;
use Liberu\RealEstate\Parties\Application\CreateParty;
use Liberu\RealEstate\Parties\Models\Party;
use Liberu\RealEstate\Properties\Domain\PropertyStatus;
use Liberu\RealEstate\Properties\Models\City;
use Liberu\RealEstate\Properties\Models\District;
use Liberu\RealEstate\Properties\Models\Property;
use RuntimeException;
use Throwable;

/**
 * Opens the Liberu file for an acquisition opportunity won in GoHighLevel: verifies it with GHL (sub-account,
 * pipeline, status "won"), links or creates the owner (Party, type vendor, with its owner profile), creates the
 * property in draft and the mandate (Instruction, draft) ready for the team, contract and signature. Publishing
 * stays a team action.
 *
 * One clarto_ghl_handoffs row per GHL opportunity: a retried or duplicated webhook never opens a second file;
 * one owner can bring several properties (one opportunity each).
 *
 * The owner is linked automatically only when the identity is unambiguous (matchOwner()). Otherwise the property
 * and mandate are created without owner and the file waits as "pendiente" in Propietarios → Pendientes de
 * vinculación, where a person picks the right owner or confirms a new one (link()).
 */
final class GhlHandoff extends Model
{
    protected $table = 'clarto_ghl_handoffs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['opportunity' => 'array', 'contact' => 'array', 'candidate_party_ids' => 'array', 'processed_at' => 'datetime', 'linked_at' => 'datetime'];
    }

    /** Files waiting for a person to choose the owner, for one team (handoffs carry no team: via their mandate). */
    public static function pendingForTeam(int|string|null $teamId): \Illuminate\Database\Eloquent\Builder
    {
        return self::query()->where('status', 'pendiente')
            ->whereIn('instruction_id', Instruction::query()->withTrashed()->select('id')->where('team_id', $teamId));
    }

    /** @return array{status: string, reason?: ?string, handoff: self} */
    public static function process(string $opportunityId, ?GhlClient $ghl = null): array
    {
        $ghl ??= new GhlClient;

        return Cache::lock('ghl-opportunity:'.$opportunityId, 60)->block(10, function () use ($opportunityId, $ghl): array {
            $handoff = self::query()->firstOrNew(['opportunity_id' => $opportunityId]);
            if ($handoff->exists && in_array($handoff->status, ['procesado', 'pendiente'], true)) {
                $handoff->increment('deliveries');

                return ['status' => 'duplicado', 'handoff' => $handoff];
            }

            $opportunity = $ghl->opportunity($opportunityId);   // the webhook body is not trusted: ask GHL
            $handoff->fill([
                'contact_id' => (string) ($opportunity['contactId'] ?? $opportunity['contact']['id'] ?? ''),
                'location_id' => (string) ($opportunity['locationId'] ?? ''),
                'pipeline_id' => (string) ($opportunity['pipelineId'] ?? ''),
                'opportunity' => $opportunity,
                'deliveries' => $handoff->exists ? $handoff->deliveries + 1 : 1,
            ]);

            $reason = match (true) {
                $opportunity === [] => 'La oportunidad no existe en GHL',
                $handoff->location_id !== (string) config('ghl.location_id') => 'Otra subcuenta de GHL',
                $handoff->pipeline_id !== (string) config('ghl.pipeline_id') => 'Otro pipeline (no es captación)',
                ($opportunity['status'] ?? '') !== 'won' => 'La oportunidad no está ganada ('.($opportunity['status'] ?? '?').')',
                $handoff->contact_id === '' => 'La oportunidad no tiene contacto',
                default => null,
            };
            if ($reason !== null) {
                $handoff->fill(['status' => 'ignorado', 'reason' => $reason])->save();

                return ['status' => 'ignorado', 'reason' => $reason, 'handoff' => $handoff];
            }

            try {
                DB::transaction(function () use ($handoff, $ghl, $opportunity): void {
                    $team = Team::query()->oldest()->firstOrFail();
                    $actor = (int) ($team->user_id ?? 1);
                    $fields = self::fields($opportunity);
                    $contact = $ghl->contact($handoff->contact_id);
                    [$party, $candidates, $ambiguity] = self::matchOwner((int) $team->getKey(), $handoff->contact_id, $contact);
                    if ($ambiguity === null) {
                        $party ??= self::createOwner((int) $team->getKey(), $actor, $handoff->contact_id, $contact);
                        self::attachOwner($party, $handoff->contact_id, $contact);
                    }
                    $property = self::draftProperty($team, $actor, $fields, $handoff);
                    $instruction = app(CreateInstruction::class)->handle($team->getKey(), $actor, [
                        'subject' => 'Encargo '.($fields['operacion'] ?? 'venta').' · '.($fields['municipio'] ?? $party?->name ?? self::contactName($contact, $handoff->contact_id)).' · '.$property->reference(),
                        'property_id' => $property->getKey(),
                        'party_id' => $party?->getKey(),
                    ]);
                    $handoff->fill([
                        'status' => $ambiguity === null ? 'procesado' : 'pendiente', 'reason' => $ambiguity, 'processed_at' => now(),
                        'party_id' => $party?->getKey(), 'property_id' => $property->getKey(), 'instruction_id' => $instruction->getKey(),
                        'candidate_party_ids' => $candidates === [] ? null : $candidates, 'contact' => $contact,
                    ])->save();
                });
            } catch (Throwable $e) {
                $handoff->fill(['status' => 'error', 'reason' => mb_strimwidth($e->getMessage(), 0, 250, '…')])->save();
                throw $e;
            }

            $handoff->refresh();

            return ['status' => $handoff->status, 'reason' => $handoff->reason, 'handoff' => $handoff];
        });
    }

    /**
     * Resolves a file waiting as "pendiente": attaches its property and mandate to the chosen owner, or to a new
     * owner created from the GHL contact when $party is null. Returns the owner.
     */
    public function link(?Party $party, ?Authenticatable $user = null): Party
    {
        return Cache::lock('ghl-opportunity:'.$this->opportunity_id, 60)->block(10, fn (): Party => DB::transaction(function () use ($party, $user): Party {
            $this->refresh();
            if ($this->status !== 'pendiente') {
                throw new RuntimeException(__('propietarios.queue.already_linked'));
            }
            $teamId = (int) (Instruction::query()->whereKey($this->instruction_id)->value('team_id') ?? Property::query()->whereKey($this->property_id)->value('team_id'));
            if ($party !== null && (int) $party->team_id !== $teamId) {
                throw new RuntimeException(__('propietarios.queue.other_team'));
            }
            $isNew = $party === null;
            $party ??= self::createOwner($teamId, (int) ($user?->getAuthIdentifier() ?? 1), $this->contact_id, (array) $this->contact);
            self::attachOwner($party, $this->contact_id, (array) $this->contact);
            Instruction::query()->whereKey($this->instruction_id)->update(['party_id' => $party->getKey()]);
            $this->fill([
                'status' => 'procesado', 'reason' => null, 'party_id' => $party->getKey(),
                'linked_by' => $user?->getAuthIdentifier(), 'linked_at' => now(),
            ])->save();
            OwnerProfile::log($party, 'ghl_linked', ['opportunity_id' => $this->opportunity_id, 'property_id' => $this->property_id, 'new_owner' => $isNew]);

            return $party;
        }));
    }

    /**
     * Who the GHL contact is. Unambiguous: the party already linked to this GHL contact; or exactly one party
     * matching by e-mail or phone whose other data (e-mail, phone, name, GHL contact) do not contradict the
     * contact; or nobody (a new owner). Anything else is ambiguous and a person decides.
     *
     * @param  array<string, mixed>  $contact
     * @return array{0: ?Party, 1: list<int>, 2: ?string} [owner, candidate ids, ambiguity reason]
     */
    public static function matchOwner(int $teamId, string $contactId, array $contact): array
    {
        $team = fn () => Party::query()->where('team_id', $teamId);
        $linked = $team()->where(fn ($q) => $q->where('metadata->ghl_contact_id', $contactId)->orWhereJsonContains('metadata->ghl_contact_ids', $contactId))->first();
        if ($linked !== null) {
            return [$linked, [], null];
        }

        $email = Str::lower(trim((string) ($contact['email'] ?? '')));
        $phone = self::phoneKey((string) ($contact['phone'] ?? ''));
        $byEmail = $email === '' ? collect() : $team()->whereRaw('lower(email) = ?', [$email])->pluck('id');
        // ponytail: phones compared in PHP over the team's parties; add a normalised phone column if they reach tens of thousands.
        $byPhone = $phone === '' ? collect() : $team()->whereNotNull('phone')->get(['id', 'phone'])->filter(fn (Party $p): bool => self::phoneKey((string) $p->phone) === $phone)->pluck('id');
        $ids = $byEmail->merge($byPhone)->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();

        if ($ids === []) {
            return [null, [], null];
        }
        if (count($ids) > 1) {
            return [null, $ids, $byEmail->isNotEmpty() && $byPhone->isNotEmpty() && $byEmail->intersect($byPhone)->isEmpty()
                ? __('propietarios.ambiguity.email_and_phone_differ')
                : __('propietarios.ambiguity.several_candidates')];
        }

        $party = $team()->findOrFail($ids[0]);
        $ghlId = (string) ($party->metadata['ghl_contact_id'] ?? '');
        $reason = match (true) {
            $email !== '' && filled($party->email) && Str::lower(trim((string) $party->email)) !== $email => __('propietarios.ambiguity.email_differs'),
            $phone !== '' && filled($party->phone) && self::phoneKey((string) $party->phone) !== $phone => __('propietarios.ambiguity.phone_differs'),
            ! self::sameName(self::contactName($contact, ''), (string) $party->name) => __('propietarios.ambiguity.name_differs'),
            $ghlId !== '' && $ghlId !== $contactId => __('propietarios.ambiguity.other_ghl_contact'),
            default => null,
        };

        return $reason === null ? [$party, [], null] : [null, $ids, $reason];
    }

    /** @param array<string, mixed> $contact */
    private static function createOwner(int $teamId, int $actor, string $contactId, array $contact): Party
    {
        $email = Str::lower(trim((string) ($contact['email'] ?? '')));
        $phone = trim((string) ($contact['phone'] ?? ''));

        return app(CreateParty::class)->handle($teamId, $actor, [
            'type' => 'vendor', 'name' => self::contactName($contact, $contactId),
            'email' => $email ?: null, 'phone' => $phone ?: null,
            'metadata' => ['ghl_contact_id' => $contactId, 'origen' => 'GoHighLevel'],
        ]);
    }

    /**
     * Keeps the GHL contact id on the owner (another GHL contact of the same person goes to ghl_contact_ids),
     * fills the party's blank e-mail/phone and creates or completes the owner profile from the contact.
     *
     * @param  array<string, mixed>  $contact
     */
    private static function attachOwner(Party $party, string $contactId, array $contact): void
    {
        $metadata = (array) $party->metadata;
        if (blank($metadata['ghl_contact_id'] ?? null)) {
            $metadata['ghl_contact_id'] = $contactId;
        } elseif ($metadata['ghl_contact_id'] !== $contactId) {
            $metadata['ghl_contact_ids'] = array_values(array_unique([...(array) ($metadata['ghl_contact_ids'] ?? []), $contactId]));
        }
        $party->forceFill([
            'metadata' => $metadata,
            'email' => $party->email ?: (Str::lower(trim((string) ($contact['email'] ?? ''))) ?: null),
            'phone' => $party->phone ?: (trim((string) ($contact['phone'] ?? '')) ?: null),
        ])->save();

        OwnerProfile::for($party, 'ghl')->prefillFromGhl($contact);
    }

    /** @param array<string, mixed> $contact */
    private static function contactName(array $contact, string $contactId): string
    {
        $name = trim((string) ($contact['name'] ?? '')) ?: trim(($contact['firstName'] ?? '').' '.($contact['lastName'] ?? ''));

        return $name !== '' ? $name : ($contactId !== '' ? 'Propietario '.$contactId : '');
    }

    /** Last nine digits of a Spanish phone: "+34 600 00 00 01" and "600000001" are the same phone. */
    private static function phoneKey(string $phone): string
    {
        $digits = (string) preg_replace('/\D/', '', $phone);

        return strlen($digits) > 9 ? substr($digits, -9) : $digits;
    }

    /** Same person by name: every word of the shorter name is in the longer one (accents and case ignored). */
    private static function sameName(string $a, string $b): bool
    {
        $words = fn (string $s): array => array_values(array_filter(preg_split('/[^a-z]+/', Str::lower(Str::ascii($s))) ?: [], fn (string $w): bool => strlen($w) > 1));
        [$x, $y] = [$words($a), $words($b)];
        if ($x === [] || $y === [] || str_starts_with(Str::lower($b), 'propietario ')) {
            return true;
        }
        [$short, $long] = count($x) <= count($y) ? [$x, $y] : [$y, $x];

        return array_diff($short, $long) === [];
    }

    /** @param array<string, ?string> $f */
    private static function draftProperty(Team $team, int $actor, array $f, self $handoff): Property
    {
        $city = filled($f['municipio'] ?? null) ? City::query()->whereRaw('lower(name) = ?', [Str::lower($f['municipio'])])->first() : null;
        $district = filled($f['zona'] ?? null) ? District::query()->whereRaw('lower(name) = ?', [Str::lower($f['zona'])])->first() : null;

        $property = new Property;
        $property->forceFill([
            'team_id' => $team->getKey(),
            'created_by' => $actor,
            'status' => PropertyStatus::Draft,
            'deal_type' => ($f['operacion'] ?? '') === 'alquiler' ? 'rent' : 'sale',
            'property_type' => ['piso' => 'apartment', 'atico' => 'apartment', 'casa' => 'house', 'chalet' => 'villa', 'local' => 'commercial', 'terreno' => 'land'][$f['tipo_inmueble'] ?? ''] ?? 'residential',
            'address' => trim(implode(', ', array_filter([$f['direccion'] ?? null, $f['zona'] ?? null, $f['codigo_postal'] ?? null, $f['municipio'] ?? null]))) ?: 'Pendiente de completar',
            'postal_code' => $f['codigo_postal'] ?? null,
            'area_sqft' => is_numeric($f['superficie_m2'] ?? null) ? (float) $f['superficie_m2'] : null,
            'bedrooms' => is_numeric($f['dormitorios'] ?? null) ? (int) $f['dormitorios'] : null,
            'bathrooms' => is_numeric($f['banos'] ?? null) ? (int) $f['banos'] : null,
            'city_id' => $city?->getKey(),
            'region_id' => $city?->region_id,
            'district_id' => $district?->getKey(),
            'country' => 'ES',
            'currency' => 'EUR',
            'internal_notes' => "Captación ganada en GoHighLevel.\nOportunidad: {$handoff->opportunity_id} · Contacto: {$handoff->contact_id}".(filled($f['plazo'] ?? null) ? "\nPlazo indicado: {$f['plazo']}" : ''),
        ])->save();

        return $property;
    }

    /**
     * Opportunity custom fields by our names (config ghl.fields maps them to GHL field ids).
     *
     * @param  array<string, mixed>  $opportunity
     * @return array<string, ?string>
     */
    private static function fields(array $opportunity): array
    {
        $values = [];
        foreach ((array) ($opportunity['customFields'] ?? []) as $field) {
            $value = $field['fieldValue'] ?? $field['field_value'] ?? $field['value'] ?? $field['fieldValueString'] ?? null;
            $values[(string) ($field['id'] ?? '')] = is_array($value) ? implode(', ', $value) : (string) $value;
        }

        $out = [];
        foreach ((array) config('ghl.fields') as $name => $id) {
            $out[$name] = filled($id) ? ($values[$id] ?? null) : null;
        }

        return $out;
    }
}
