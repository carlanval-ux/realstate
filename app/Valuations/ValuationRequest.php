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
            'ghl_synced_at' => 'datetime',
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

    /** Signed, expiring link to the current report (no login): the web and GHL get this one. */
    public function reportUrl(): ?string
    {
        $run = $this->currentRun;
        if ($run?->pdf_path === null) {
            return null;
        }

        return URL::temporarySignedRoute('valoraciones.informe', now()->addDays(max(1, (int) config('valoraciones.informe_dias', 30))),
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
        ];
    }

    public function address(): string
    {
        return (string) ($this->property['direccion_confirmada'] ?? $this->catastro['direccion'] ?? '—');
    }
}
