<?php

declare(strict_types=1);

namespace App\Albaranes;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\RealEstate\Instructions\Models\Instruction;
use Liberu\RealEstate\Parties\Models\Party;
use Liberu\RealEstate\Properties\Models\Property;

/**
 * Albarán de servicio: services done for a client (an owner), optionally tied to a property and a mandate.
 * It is sent to the billing program as pending invoicing; the billing program issues (and numbers) the invoice.
 */
final class Albaran extends Model
{
    use SoftDeletes;

    public const DRAFT = 'borrador';

    /** Confirmed; being sent or sent without confirmation (a retry checks before sending again). */
    public const PENDING = 'pendiente_envio';

    public const SENT = 'enviado';

    public const ERROR = 'error';

    public const INVOICED = 'facturado';

    public const STATUSES = [self::DRAFT, self::PENDING, self::SENT, self::ERROR, self::INVOICED];

    public const COLORS = [self::DRAFT => 'gray', self::PENDING => 'warning', self::SENT => 'info', self::ERROR => 'danger', self::INVOICED => 'success'];

    public const CONFORMITY_NONE = 'no_requerida';

    public const CONFORMITY_PENDING = 'pendiente';

    public const CONFORMITY_SIGNED = 'firmada';

    /**
     * Set by the signing feature (Formularios y firma) at boot: starts a signature request for the albarán's PDF
     * and returns its id; it calls markConformitySigned() when the client signs. Null = not connected yet.
     *
     * @var (callable(self): int|string)|null
     */
    public static $conformityRequester = null;

    protected $table = 'clarto_albaranes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'service_date' => 'date', 'invoice_date' => 'date', 'last_attempt_at' => 'datetime', 'sent_at' => 'datetime',
            'last_response' => 'array', 'total' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $a): void {
            $a->number ??= 'tmp-'.Str::ulid();
            $a->idempotency_key ??= (string) Str::uuid();
            $a->status ??= self::DRAFT;
        });
        // Our own correlative number, from the id (never repeated, no race). Not an invoice number.
        static::created(function (self $a): void {
            if (str_starts_with($a->number, 'tmp-')) {
                $a->forceFill(['number' => sprintf('ALB-%d-%05d', now()->year, $a->getKey())])->saveQuietly();
            }
        });
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function instruction(): BelongsTo
    {
        return $this->belongsTo(Instruction::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(AlbaranLine::class)->orderBy('sort')->orderBy('id');
    }

    /** Lines and client data can change only before anything reached the billing program. */
    public function isEditable(): bool
    {
        return in_array($this->status, [self::DRAFT, self::ERROR], true) && blank($this->external_id);
    }

    public function statusLabel(): string
    {
        return __('albaranes.statuses.'.$this->status);
    }

    /** Recomputes the total and whether the client's conformity is needed (any catalogue service that asks for it). */
    public function recalculate(): void
    {
        $lines = $this->lines()->get();
        $catalogue = BillingCatalogue::services();
        $needsConformity = $lines->contains(fn (AlbaranLine $l): bool => (bool) ($catalogue[$l->service_code]['conformidad'] ?? false));

        $this->forceFill([
            'total' => round((float) $lines->sum('amount'), 2),
            'conformity' => match (true) {
                $this->conformity === self::CONFORMITY_SIGNED => self::CONFORMITY_SIGNED,
                $needsConformity => self::CONFORMITY_PENDING,
                default => self::CONFORMITY_NONE,
            },
        ])->saveQuietly();
    }

    public function markConformitySigned(int|string|null $signatureRequestId = null): void
    {
        $this->forceFill(['conformity' => self::CONFORMITY_SIGNED, 'signature_request_id' => $signatureRequestId ?? $this->signature_request_id])->save();
    }

    /**
     * Client data to prefill from the owner: name, e-mail and phone from the party; tax id and fiscal address from
     * the owner profile (Propietarios) when it exists, else from the party's metadata.
     *
     * @return array{client_name: string, client_tax_id: ?string, client_email: ?string, client_phone: ?string, client_address: ?string}
     */
    public static function clientDataFor(Party $party): array
    {
        $profile = Schema::hasTable('clarto_owner_profiles') ? DB::table('clarto_owner_profiles')->where('party_id', $party->getKey())->first() : null;
        $meta = (array) $party->metadata;
        $address = $profile !== null
            ? implode(', ', array_filter([$profile->fiscal_address, trim(($profile->fiscal_postal_code ?? '').' '.($profile->fiscal_city ?? '')), $profile->fiscal_province]))
            : ($meta['address'] ?? $meta['direccion'] ?? null);

        return [
            'client_name' => (string) ($profile?->legal_name ?: $party->name),
            'client_tax_id' => $profile?->tax_id ?: ($meta['tax_id'] ?? $meta['nif'] ?? null),
            'client_email' => $party->email,
            'client_phone' => $party->phone,
            'client_address' => filled($address) ? (string) $address : null,
        ];
    }
}
