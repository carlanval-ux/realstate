<?php

declare(strict_types=1);

namespace App\Valuations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\URL;

/**
 * A valuation request from the website, one per request_id (the web generates it when the form opens: a retry
 * returns this same row, never a second GHL opportunity). Inputs, Catastro data, GHL ids and errors live here;
 * every calculation is a ValuationRun (history kept on recalculation).
 */
final class ValuationRequest extends Model
{
    protected $table = 'clarto_valuation_requests';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'property' => 'array', 'contact' => 'array', 'consent' => 'array', 'attribution' => 'array', 'catastro' => 'array', 'delivery' => 'array',
            'ghl_synced_at' => 'datetime', 'report_links_revoked_at' => 'datetime',
        ];
    }

    /** @return HasMany<ValuationRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(ValuationRun::class)->orderBy('number');
    }

    /** @return BelongsTo<ValuationRun, $this> */
    public function currentRun(): BelongsTo
    {
        return $this->belongsTo(ValuationRun::class, 'current_run_id');
    }

    /** @param Builder<self> $query */
    public function scopeVisibleTo(Builder $query, ?object $user): Builder
    {
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return $query;
        }
        $teams = collect(method_exists($user, 'allTeams') ? $user->allTeams() : [])->map->getKey()
            ->filter(fn ($teamId): bool => ValuationAccess::allows($user, $teamId))->values()->all();

        return $query->whereIn('team_id', $teams);
    }

    /** @return HasMany<ReportLink, $this> */
    public function reportLinks(): HasMany
    {
        return $this->hasMany(ReportLink::class)->latest('id');
    }

    /** Public short link to the current report (https://valoraciones.clarto.es/r/{codigo}), from the central generator. */
    public function reportUrl(string $origin = ReportLinks::PANEL): ?string
    {
        return ReportLinks::issue($this, $origin)['url'] ?? null;
    }

    /**
     * The signed panel.clarto.es link sent before the short links existed. Nothing sends it any more; the route keeps
     * serving the ones already sent until they expire or the team revokes the request's links (tests use this).
     */
    public function signedReportUrl(): ?string
    {
        $run = $this->currentRun;
        if ($run?->pdf_path === null) {
            return null;
        }

        return URL::temporarySignedRoute('valoraciones.informe', now()->addDays(ReportLinks::days()),
            ['requestId' => $this->request_id, 'run' => $run->number]);
    }

    /**
     * The API answer (contract docs/clarto/valoraciones.md · orquestacion/valoraciones-api.md).
     *
     * @return array<string, mixed>
     */
    public function apiResponse(): array
    {
        $run = $this->currentRun;
        $estado = match (true) {
            $run === null && $this->status === 'error' => 'error',
            $run === null => 'procesando',
            $run->status === 'estimada' => 'recibida',   // the result reaches the client by e-mail and WhatsApp, not the browser
            default => $run->status,
        };

        return [
            'estado' => $estado,
            'referencia' => $this->reference,
            'envio' => ValuationDelivery::summary($this),
            'datos_simulados' => (bool) $run?->simulated,
            'mensaje' => __('clarto.valoraciones.mensajes.'.$estado),
            'motivo' => $estado === 'revision' ? $run->message : null,
            // Stable reason code of a review (sin_datos_zona, catastro_foral, zona_no_representativa, no_vivienda…).
            'motivo_codigo' => $estado === 'revision' ? ($run->result['codigo'] ?? null) : null,
            'oficina_nombre' => $this->office(),           // L-13: the web's final screen offers the visit
            'enlace_cita' => $this->bookingUrl('gracias'),
        ];
    }

    public function address(): string
    {
        return (string) ($this->property['direccion_confirmada'] ?? $this->catastro['direccion'] ?? '—');
    }

    /** «Calle Castellón 2, Puerto de Sagunto»: street and number + municipality, without floor or door (texts to the client, GHL). */
    public function shortAddress(): string
    {
        $municipality = mb_convert_case((string) ($this->catastro['municipio'] ?? $this->property['municipio'] ?? ''), MB_CASE_TITLE);

        return trim(ReportData::readable($this->address())[0].($municipality !== '' ? ', '.$municipality : ''), ', ');
    }

    /** Office of the property and its booking link (L-11, L-13): what the web, the e-mail, WhatsApp and the PDF offer. */
    public function office(): string
    {
        return Office::forPostalCode((string) ($this->property['codigo_postal'] ?? $this->catastro['codigo_postal'] ?? ''));
    }

    public function bookingUrl(string $source): ?string
    {
        return Office::bookingUrl($this->office(), $source);
    }

    /**
     * Where «Solicitar valoración personalizada» leads: the office's calendar if there is one, else (owner, 21/11/2026:
     * no calendars for now, the visit is arranged by WhatsApp or phone) a WhatsApp chat with the reference.
     */
    public function visitUrl(string $source): string
    {
        return $this->bookingUrl($source)
            ?? 'https://wa.me/34'.preg_replace('/\D/', '', ReportData::WHATSAPP).'?text='.rawurlencode('Hola, quisiera solicitar la valoración personalizada de mi inmueble (ref. '.$this->reference.').');
    }
}
