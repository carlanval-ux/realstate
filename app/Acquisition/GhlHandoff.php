<?php

declare(strict_types=1);

namespace App\Acquisition;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\RealEstate\Instructions\Application\CreateInstruction;
use Liberu\RealEstate\Parties\Application\CreateParty;
use Liberu\RealEstate\Parties\Models\Party;
use Liberu\RealEstate\Properties\Domain\PropertyStatus;
use Liberu\RealEstate\Properties\Models\City;
use Liberu\RealEstate\Properties\Models\District;
use Liberu\RealEstate\Properties\Models\Property;
use Throwable;

/**
 * Opens the Liberu file for an acquisition opportunity won in GoHighLevel: verifies it with GHL (sub-account,
 * pipeline, status "won"), links or creates the owner (Party, type vendor), creates the property in draft and the
 * mandate (Instruction, draft) ready for the team, contract and signature. Publishing stays a team action.
 *
 * One clarto_ghl_handoffs row per GHL opportunity: a retried or duplicated webhook never opens a second file;
 * one owner can bring several properties (one opportunity each).
 */
final class GhlHandoff extends Model
{
    protected $table = 'clarto_ghl_handoffs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['opportunity' => 'array', 'processed_at' => 'datetime'];
    }

    /** @return array{status: string, reason?: string, handoff: self} */
    public static function process(string $opportunityId, ?GhlClient $ghl = null): array
    {
        $ghl ??= new GhlClient;

        return Cache::lock('ghl-opportunity:'.$opportunityId, 60)->block(10, function () use ($opportunityId, $ghl): array {
            $handoff = self::query()->firstOrNew(['opportunity_id' => $opportunityId]);
            if ($handoff->exists && $handoff->status === 'procesado') {
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
                    $party = self::owner($team, $actor, $handoff->contact_id, $ghl->contact($handoff->contact_id));
                    $property = self::draftProperty($team, $actor, $fields, $handoff);
                    $instruction = app(CreateInstruction::class)->handle($team->getKey(), $actor, [
                        'subject' => 'Encargo '.($fields['operacion'] ?? 'venta').' · '.($fields['municipio'] ?? $party->name).' · '.$property->reference(),
                        'property_id' => $property->getKey(),
                        'party_id' => $party->getKey(),
                    ]);
                    $handoff->fill([
                        'status' => 'procesado', 'reason' => null, 'processed_at' => now(),
                        'party_id' => $party->getKey(), 'property_id' => $property->getKey(), 'instruction_id' => $instruction->getKey(),
                    ])->save();
                });
            } catch (Throwable $e) {
                $handoff->fill(['status' => 'error', 'reason' => mb_strimwidth($e->getMessage(), 0, 250, '…')])->save();
                throw $e;
            }

            return ['status' => 'procesado', 'handoff' => $handoff->refresh()];
        });
    }

    /**
     * The owner: the party already linked to this GHL contact, one with the same e-mail or phone, or a new one.
     *
     * @param  array<string, mixed>  $contact
     */
    private static function owner(Team $team, int $actor, string $contactId, array $contact): Party
    {
        $email = Str::lower(trim((string) ($contact['email'] ?? '')));
        $phone = trim((string) ($contact['phone'] ?? ''));
        $name = trim((string) ($contact['name'] ?? trim(($contact['firstName'] ?? '').' '.($contact['lastName'] ?? ''))));

        $party = Party::query()->where('team_id', $team->getKey())->where('metadata->ghl_contact_id', $contactId)->first()
            ?? Party::query()->where('team_id', $team->getKey())
                ->where(fn ($q) => $q->when($email !== '', fn ($q) => $q->where('email', $email))->when($phone !== '', fn ($q) => $q->orWhere('phone', $phone)))
                ->when($email === '' && $phone === '', fn ($q) => $q->whereRaw('1 = 0'))
                ->first();

        if ($party === null) {
            return app(CreateParty::class)->handle($team->getKey(), $actor, [
                'type' => 'vendor', 'name' => $name !== '' ? $name : 'Propietario '.$contactId,
                'email' => $email ?: null, 'phone' => $phone ?: null,
                'metadata' => ['ghl_contact_id' => $contactId, 'origen' => 'GoHighLevel'],
            ]);
        }

        $party->forceFill(['metadata' => array_merge((array) $party->metadata, ['ghl_contact_id' => $contactId])])->save();

        return $party;
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
