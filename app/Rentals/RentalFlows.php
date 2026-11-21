<?php

declare(strict_types=1);

namespace App\Rentals;

use App\Rentals\Integration\AlbaranGateway;
use App\Rentals\Integration\CommunicationLog;
use App\Rentals\Integration\FormsGateway;
use App\Rentals\Integration\TravellerRegistry;
use App\Rentals\Models\Occupancy;
use App\Rentals\Models\RentalCharge;
use App\Rentals\Models\RentalDocument;
use App\Rentals\Models\RentalGuarantee;
use App\Rentals\Models\RentalIncident;
use App\Rentals\Models\RentalSeason;
use App\Rentals\Models\RentalSettings;
use App\Rentals\Models\RentalUnit;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Operations on top of the occupancy calendar (App\Rentals\Occupancies): rent and utilities, non-payment, deposits
 * and guarantees, incidents and cleaning, documents for «Formularios», communications, holiday bookings, SES and the
 * owner settlement. Integrations are resolved from the container (see ClartoServiceProvider, «Alquileres» block).
 */
final class RentalFlows
{
    public const CHANNELS = ['whatsapp', 'sms', 'email'];

    public const PURPOSES = ['servicio', 'cobro', 'incidencia', 'contrato', 'viajeros', 'liquidacion'];

    // ── Rent, utilities, non-payment ─────────────────────────────────────────────────────────────────────────

    /** Monthly rent of $period (YYYY-MM), due on the contract's due day. */
    public static function issueRent(Occupancy $occupancy, string $period): RentalCharge
    {
        $due = CarbonImmutable::createFromFormat('Y-m-d', $period.'-01')->startOfDay()->day(min((int) ($occupancy->due_day ?: 1), 28));

        return self::charge($occupancy, 'renta', (float) $occupancy->rent_amount, $due, 'inquilino', $period, __('clarto.alquileres.concepts.renta').' '.$period);
    }

    /**
     * Split one supply bill among the occupancies running in the property during $period, as each contract says:
     * utilities[supply] = {modo: porcentaje|importe|incluido, valor}. The rest (if any) is the owner's.
     *
     * @return list<RentalCharge>
     */
    public static function splitUtilityBill(int $propertyId, string $supply, float $total, string $period, DateTimeInterface|string $due): array
    {
        $first = CarbonImmutable::createFromFormat('Y-m-d', $period.'-01')->startOfDay();
        $charges = [];
        $running = Occupancy::query()->where('property_id', $propertyId)->whereIn('status', ['en_curso', 'confirmada', 'finalizada'])
            ->get()->filter(fn (Occupancy $o): bool => $o->start()->lt($first->addMonth()) && $o->end()->gt($first));
        foreach ($running as $occupancy) {
            $rule = $occupancy->utilities[$supply] ?? null;
            $amount = match ($rule['modo'] ?? 'incluido') {
                'porcentaje' => round($total * (float) $rule['valor'] / 100, 2),
                'importe' => (float) $rule['valor'],
                default => 0.0,
            };
            if ($amount > 0) {
                $charges[] = self::charge($occupancy, 'suministro', $amount, $due, 'inquilino', $period, __('clarto.alquileres.concepts.suministro').': '.$supply.' '.$period, ['suministro' => $supply, 'factura_total' => $total, 'regla' => $rule]);
            }
        }

        return $charges;
    }

    public static function pay(RentalCharge $charge, DateTimeInterface|string $on): RentalCharge
    {
        $charge->forceFill(['status' => 'cobrado', 'paid_on' => CarbonImmutable::parse($on)])->save();
        Occupancies::record($charge->occupancy, 'cobro', ['cargo' => $charge->getKey(), 'importe' => (float) $charge->amount, 'fecha' => CarbonImmutable::parse($on)->toDateString()]);

        return $charge;
    }

    /** Overdue and unpaid: flagged, logged in the history and a payment reminder recorded for the tenant. */
    public static function markUnpaid(RentalCharge $charge, DateTimeInterface|string $on): RentalCharge
    {
        $day = CarbonImmutable::parse($on);
        if ($charge->status !== 'pendiente' || $day->lte($charge->due_on)) {
            throw new RentalRefused(__('clarto.alquileres.errors.not_overdue'));
        }
        $charge->forceFill(['status' => 'impagado'])->save();
        $occupancy = $charge->occupancy;
        Occupancies::record($occupancy, 'impago', ['cargo' => $charge->getKey(), 'concepto' => $charge->concept, 'importe' => (float) $charge->amount, 'vencimiento' => $charge->due_on->toDateString(), 'fecha' => $day->toDateString()]);
        $tenant = $occupancy->people()->whereIn('role', ['arrendatario', 'titular'])->first();
        if ($tenant) {
            self::communicate($occupancy, $tenant->phone ? 'whatsapp' : 'email', 'cobro', (string) ($tenant->phone ?: $tenant->email),
                __('clarto.alquileres.messages.unpaid_subject'), __('clarto.alquileres.messages.unpaid_body', ['amount' => number_format((float) $charge->amount, 2, ',', '.'), 'due' => $charge->due_on->format('d/m/Y')]));
        }

        return $charge;
    }

    // ── Deposits and guarantees ──────────────────────────────────────────────────────────────────────────────

    public static function receiveGuarantee(Occupancy $occupancy, string $type, float $amount, DateTimeInterface|string $on, ?string $provider = null): RentalGuarantee
    {
        if (! in_array($type, RentalGuarantee::TYPES, true) || $amount <= 0) {
            throw new RentalRefused(__('clarto.alquileres.errors.guarantee'));
        }
        $monthly = $occupancy->rent_period === 'mes' ? (float) $occupancy->rent_amount : 0.0;
        if ($type === 'fianza' && ($legal = RentalRules::legalDeposit($occupancy)) > 0 && abs($amount - $legal) > 0.009) {
            throw new RentalRefused(__('clarto.alquileres.errors.deposit_amount', ['amount' => number_format($legal, 2, ',', '.')]));
        }
        $maxMonths = RentalRules::EXTRA_GUARANTEE_MAX_MONTHS[$occupancy->purpose] ?? null;
        if ($type === 'garantia_adicional' && $maxMonths !== null && $amount > $maxMonths * $monthly + 0.009) {
            throw new RentalRefused(__('clarto.alquileres.errors.extra_guarantee', ['months' => $maxMonths]));
        }
        // LAU art. 36.5 (RDL 29/2026): a dwelling tenant cannot be required to take out rent-default insurance.
        if ($type === 'seguro_impago' && in_array($occupancy->purpose, ['habitual', 'temporal'], true)) {
            throw new RentalRefused(__('clarto.alquileres.errors.insurance_not_required'));
        }
        $guarantee = $occupancy->guarantees()->create(['team_id' => $occupancy->team_id, 'type' => $type, 'amount' => $amount, 'provider' => $provider, 'status' => 'recibida', 'received_on' => CarbonImmutable::parse($on)]);
        Occupancies::record($occupancy, 'garantia_recibida', ['tipo' => $type, 'importe' => $amount, 'fecha' => CarbonImmutable::parse($on)->toDateString()]);

        return $guarantee;
    }

    /** Resguardo de depósito (Comunitat Valenciana: deposit with the Generalitat). */
    public static function lodgeDeposit(RentalGuarantee $guarantee, string $receipt, DateTimeInterface|string $on, string $authority = 'Generalitat Valenciana'): RentalGuarantee
    {
        if ($guarantee->type !== 'fianza' || $guarantee->status !== 'recibida' || blank($receipt)) {
            throw new RentalRefused(__('clarto.alquileres.errors.lodge'));
        }
        $day = CarbonImmutable::parse($on)->startOfDay();
        $guarantee->forceFill(['status' => 'depositada', 'deposit_receipt' => $receipt, 'deposited_on' => $day, 'deposit_authority' => $authority])->save();
        $surcharge = RentalRules::lateLodgingSurcharge($guarantee->occupancy, $day);
        Occupancies::record($guarantee->occupancy, 'fianza_depositada', ['resguardo' => $receipt, 'organismo' => $authority, 'fecha' => $day->toDateString(),
            'aviso' => $surcharge > 0 ? __('clarto.alquileres.warnings.late_lodging', ['pct' => $surcharge]) : null]);

        return $guarantee;
    }

    /**
     * Final settlement of a deposit or guarantee: every deduction needs an amount and a justification (an incident,
     * an unpaid charge or a text). The rest is returned. Late returns (LAU art. 36.4) are flagged.
     *
     * @param  list<array{concept: string, amount: float, justification: string, incident_id?: int, charge_id?: int}>  $deductions
     */
    public static function settleGuarantee(RentalGuarantee $guarantee, array $deductions, DateTimeInterface|string $on): RentalGuarantee
    {
        if (! in_array($guarantee->status, ['recibida', 'depositada'], true)) {
            throw new RentalRefused(__('clarto.alquileres.errors.guarantee_settled'));
        }
        $occupancy = $guarantee->occupancy;
        if ($occupancy->status !== 'finalizada') {
            throw new RentalRefused(__('clarto.alquileres.errors.guarantee_before_exit'));
        }
        $total = 0.0;
        foreach ($deductions as $d) {
            if (($d['amount'] ?? 0) <= 0 || blank($d['justification'] ?? null) || blank($d['concept'] ?? null)) {
                throw new RentalRefused(__('clarto.alquileres.errors.deduction_justified'));
            }
            $total += (float) $d['amount'];
        }
        if ($total > (float) $guarantee->amount + 0.009) {
            throw new RentalRefused(__('clarto.alquileres.errors.deduction_exceeds'));
        }
        // LAU art. 36.7: without a signed end-of-lease record listing the damage, the dwelling is presumed returned in good
        // condition. Deductions for unpaid charges do not need it; damage deductions do.
        $damage = collect($deductions)->contains(fn (array $d): bool => empty($d['charge_id']));
        if ($damage && ! $occupancy->documents()->where('kind', 'acta_salida')->whereIn('status', ['preparado', 'enviado_firma', 'firmado'])->exists()) {
            throw new RentalRefused(__('clarto.alquileres.errors.exit_record_required'));
        }
        $day = CarbonImmutable::parse($on);
        $returned = round((float) $guarantee->amount - $total, 2);
        $guarantee->forceFill([
            'status' => $total == 0.0 ? 'devuelta' : ($returned > 0 ? 'devuelta_parcial' : 'ejecutada'),
            'returned_amount' => $returned, 'returned_on' => $day, 'deductions' => $deductions,
        ])->save();
        $late = $day->gt(RentalRules::depositRefundDeadline($occupancy->end()));
        Occupancies::record($occupancy, 'liquidacion_garantia', ['tipo' => $guarantee->type, 'importe' => (float) $guarantee->amount, 'retenido' => $total, 'devuelto' => $returned,
            'fuera_de_plazo' => $late ?: null, 'recuperar_deposito' => $guarantee->deposit_receipt, 'fecha' => $day->toDateString()]);
        if ($returned > 0) {
            self::charge($occupancy, 'devolucion', $returned, $day, 'propietario', null, __('clarto.alquileres.concepts.devolucion_garantia', ['type' => __('clarto.alquileres.guarantee_types.'.$guarantee->type)]), ['garantia' => $guarantee->getKey()])
                ->forceFill(['status' => 'devuelto', 'paid_on' => $day])->save();
        }

        return $guarantee;
    }

    // ── Incidents and cleaning ───────────────────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $attributes */
    public static function openIncident(Occupancy $occupancy, array $attributes): RentalIncident
    {
        $incident = $occupancy->incidents()->create($attributes + [
            'team_id' => $occupancy->team_id, 'property_id' => $occupancy->property_id, 'unit_id' => $occupancy->unit_id,
            'reported_on' => now()->toDateString(), 'status' => 'abierta',
        ]);
        Occupancies::record($occupancy, $incident->kind === 'limpieza' ? 'limpieza_programada' : 'incidencia', ['incidencia' => $incident->getKey(), 'titulo' => $incident->title, 'prioridad' => $incident->priority]);

        return $incident;
    }

    public static function quoteIncident(RentalIncident $incident, string $provider, float $amount, string $costBearer, ?int $providerPartyId = null): RentalIncident
    {
        if (! in_array($costBearer, ['propietario', 'inquilino', 'clarto', 'seguro'], true)) {
            throw new RentalRefused(__('clarto.alquileres.errors.cost_bearer'));
        }
        $incident->forceFill(['provider_name' => $provider, 'provider_party_id' => $providerPartyId, 'quote_amount' => $amount, 'cost_bearer' => $costBearer, 'status' => 'presupuestada'])->save();

        return $incident;
    }

    public static function resolveIncident(RentalIncident $incident, float $finalAmount): RentalIncident
    {
        if (! in_array($incident->status, ['abierta', 'presupuestada', 'en_curso'], true)) {
            throw new RentalRefused(__('clarto.alquileres.errors.incident_status'));
        }
        if ($incident->kind !== 'limpieza' && ! $incident->access_authorized && $incident->occupancy?->status === 'en_curso') {
            throw new RentalRefused(__('clarto.alquileres.errors.access_not_authorized'));
        }
        $incident->forceFill(['final_amount' => $finalAmount, 'status' => 'resuelta'])->save();

        return $incident;
    }

    /** Verified closure by someone other than the provider; the tenant's share becomes a charge. */
    public static function verifyIncident(RentalIncident $incident, int $verifierId): RentalIncident
    {
        if ($incident->status !== 'resuelta') {
            throw new RentalRefused(__('clarto.alquileres.errors.incident_status'));
        }
        $incident->forceFill(['status' => 'cerrada', 'verified_by' => $verifierId, 'verified_at' => now()])->save();
        if ($incident->occupancy) {
            Occupancies::record($incident->occupancy, $incident->kind === 'limpieza' ? 'limpieza_verificada' : 'incidencia_cerrada', ['incidencia' => $incident->getKey(), 'importe' => (float) $incident->final_amount, 'paga' => $incident->cost_bearer]);
            if ($incident->cost_bearer === 'inquilino' && (float) $incident->final_amount > 0) {
                self::charge($incident->occupancy, 'reparacion', (float) $incident->final_amount, now(), 'inquilino', null, $incident->title, ['incidencia' => $incident->getKey()]);
            }
        }

        return $incident;
    }

    // ── Documents, communications, albaranes ─────────────────────────────────────────────────────────────────

    /**
     * Prepare a contract, annex, inventory or record in «Formularios» with prefilled data and, if asked, send it to
     * signature. Fictitious templates until the final ones are approved.
     *
     * @param  array<string, mixed>  $values
     */
    public static function prepareDocument(Occupancy $occupancy, string $kind, array $values = [], bool $sendToSignature = false, ?object $user = null): RentalDocument
    {
        RentalAccess::ensure($user ?? auth()->user(), RentalAccess::CONTRACTS);
        if (! in_array($kind, RentalDocument::KINDS, true)) {
            throw new RentalRefused(__('clarto.alquileres.errors.document_kind'));
        }
        $templates = RentalSettings::forTeam($occupancy->team_id)['plantillas'];
        $template = $kind === 'contrato'
            ? ($templates[$occupancy->module === 'habitaciones' ? 'contrato_habitacion' : 'contrato_'.$occupancy->purpose] ?? $templates['contrato_habitual'])
            : $templates[$kind];
        $people = $occupancy->people()->get();
        $document = $occupancy->documents()->create([
            'team_id' => $occupancy->team_id, 'kind' => $kind, 'template' => $template, 'status' => 'borrador',
            'title' => __('clarto.alquileres.document_kinds.'.$kind).' · '.$occupancy->unit->label(),
            'values' => $values + [
                'unidad' => $occupancy->unit->label(), 'finalidad' => $occupancy->purpose, 'causa_temporalidad' => $occupancy->temporal_cause,
                'inicio' => $occupancy->planned_start->toDateString(), 'fin' => $occupancy->planned_end->toDateString(),
                'renta' => (float) $occupancy->rent_amount, 'periodo_renta' => $occupancy->rent_period, 'dia_pago' => $occupancy->due_day,
                'suministros' => $occupancy->utilities, 'personas' => $people->map(fn ($p): array => ['rol' => $p->role, 'nombre' => $p->name])->all(),
            ],
        ]);
        $signers = $people->whereIn('role', ['firmante', 'arrendatario', 'avalista', 'propietario'])
            ->map(fn ($p): array => ['name' => $p->name, 'email' => $p->email, 'phone' => $p->phone, 'role' => $p->role])->values()->all();
        $reference = app(FormsGateway::class)->prepare($document, $signers, $sendToSignature);
        $document->forceFill(['external_ref' => $reference, 'status' => $sendToSignature ? 'enviado_firma' : 'preparado'])->save();

        return $document;
    }

    /** Log a WhatsApp / SMS / e-mail for a service purpose (marketing is not a rental purpose). */
    public static function communicate(Occupancy $occupancy, string $channel, string $purpose, string $to, string $subject, string $body): void
    {
        if (! in_array($channel, self::CHANNELS, true) || ! in_array($purpose, self::PURPOSES, true) || blank($to)) {
            throw new RentalRefused(__('clarto.alquileres.errors.communication'));
        }
        app(CommunicationLog::class)->log($occupancy, $channel, $purpose, $to, $subject, $body);
    }

    /**
     * CLARTO service that must reach invoicing: albarán through the integration point and a «servicio_clarto» charge.
     * LAU art. 20.2 (RDL 29/2026): preparation, formalisation, management, novation or renewal of a dwelling lease are
     * never charged to the tenant; an optional service only if the tenant asked for it in writing (solicitud_escrita).
     *
     * @param  list<array{description: string, quantity: float|int, unit_price: float|int, tipo?: string, solicitud_escrita?: bool}>  $lines
     */
    public static function requestAlbaran(Occupancy $occupancy, array $lines, string $payer, ?int $partyId = null): RentalCharge
    {
        if ($payer === 'inquilino' && in_array($occupancy->purpose, ['habitual', 'temporal'], true)) {
            foreach ($lines as $line) {
                if (in_array($line['tipo'] ?? null, RentalRules::LANDLORD_FEES, true)) {
                    throw new RentalRefused(__('clarto.alquileres.errors.fees_landlord'));
                }
                if (($line['tipo'] ?? null) === 'opcional' && empty($line['solicitud_escrita'])) {
                    throw new RentalRefused(__('clarto.alquileres.errors.optional_service_request'));
                }
            }
        }
        $total = round(array_sum(array_map(fn (array $l): float => (float) $l['quantity'] * (float) $l['unit_price'], $lines)), 2);
        $reference = 'alquiler-'.$occupancy->getKey().'-'.now()->format('YmdHisv');
        $number = app(AlbaranGateway::class)->request($occupancy, $partyId, $lines, $reference);

        $charge = self::charge($occupancy, 'servicio_clarto', $total, now(), $payer, null, implode('; ', array_column($lines, 'description')), ['lineas' => $lines]);
        $charge->forceFill(['albaran_ref' => $number])->save();

        return $charge;
    }

    // ── Holiday rental ───────────────────────────────────────────────────────────────────────────────────────

    /** @return array{total: float, nights: int, detail: array<string, float>} nightly price by season */
    public static function quote(RentalUnit $unit, DateTimeInterface|string $start, DateTimeInterface|string $end): array
    {
        $from = CarbonImmutable::parse($start)->startOfDay();
        $to = CarbonImmutable::parse($end)->startOfDay();
        $seasons = RentalSeason::query()->where('unit_id', $unit->getKey())->get();
        $detail = [];
        for ($night = $from; $night->lt($to); $night = $night->addDay()) {
            $season = $seasons->first(fn (RentalSeason $s): bool => $s->starts_on->lte($night) && $s->ends_on->gte($night));
            if ($season === null) {
                throw new RentalRefused(__('clarto.alquileres.errors.no_rate', ['day' => $night->format('d/m/Y')]));
            }
            if ($night->eq($from) && $from->diffInDays($to) < $season->min_nights) {
                throw new RentalRefused(__('clarto.alquileres.errors.min_nights', ['nights' => $season->min_nights]));
            }
            $detail[$night->toDateString()] = (float) $season->nightly_rate;
        }

        return ['total' => round(array_sum($detail), 2), 'nights' => count($detail), 'detail' => $detail];
    }

    /**
     * Holiday booking: priced by season, confirmed atomically, with its holder and travellers, rate and cleaning charges.
     *
     * @param  list<array<string, mixed>>  $people
     */
    public static function bookHoliday(RentalUnit $unit, string $start, string $end, string $channel, array $people, ?string $channelRef = null, ?int $occupants = null): Occupancy
    {
        $settings = RentalSettings::forTeam($unit->team_id);
        if (! in_array($channel, $settings['canales'], true)) {
            throw new RentalRefused(__('clarto.alquileres.errors.channel'));
        }
        $quote = self::quote($unit, $start, $end);
        $occupancy = Occupancies::book([
            'unit_id' => $unit->getKey(), 'module' => 'vacacional', 'kind' => 'reserva', 'purpose' => 'turistica',
            'planned_start' => $start, 'planned_end' => $end, 'occupants' => $occupants ?? max(1, collect($people)->where('role', 'viajero')->count()),
            'channel' => $channel, 'channel_ref' => $channelRef, 'rent_amount' => $quote['total'], 'rent_period' => 'estancia',
        ], $people);
        $payer = $channel === 'directo' ? 'viajero' : 'canal';
        self::charge($occupancy, 'tarifa', $quote['total'], $start, $payer, null, __('clarto.alquileres.concepts.tarifa', ['nights' => $quote['nights']]), ['noches' => $quote['detail']]);
        if (($fee = (float) $settings['limpieza_vacacional']) > 0) {
            self::charge($occupancy, 'limpieza', $fee, $start, $payer, null, __('clarto.alquileres.concepts.limpieza'));
        }
        Occupancies::record($occupancy, 'ses_hospedajes', ['evento' => 'reserva'] + app(TravellerRegistry::class)->notify($occupancy, 'reserva'));

        return $occupancy;
    }

    /** Cancel a booking and refund $refundPercent of what was collected (the channel/booking policy decides it). */
    public static function cancelHoliday(Occupancy $occupancy, float $refundPercent, string $reason, DateTimeInterface|string $on): Occupancy
    {
        $paid = (float) $occupancy->charges()->where('status', 'cobrado')->whereIn('concept', ['tarifa', 'limpieza'])->sum('amount');
        $refund = round($paid * max(0, min(100, $refundPercent)) / 100, 2);
        Occupancies::cancel($occupancy, $reason, ['reembolso' => $refund, 'porcentaje' => $refundPercent]);
        Occupancies::record($occupancy, 'ses_hospedajes', ['evento' => 'anulacion'] + app(TravellerRegistry::class)->notify($occupancy, 'anulacion'));
        $occupancy->charges()->where('status', 'pendiente')->update(['status' => 'anulado']);
        if ($refund > 0) {
            self::charge($occupancy, 'devolucion', $refund, $on, 'clarto', null, __('clarto.alquileres.concepts.devolucion_reserva'))
                ->forceFill(['status' => 'devuelto', 'paid_on' => CarbonImmutable::parse($on)])->save();
        }

        return $occupancy;
    }

    /** Check-in: occupancy running and travellers communicated to SES.HOSPEDAJES (simulated driver). */
    public static function checkIn(Occupancy $occupancy, DateTimeInterface|string $on): array
    {
        $result = app(TravellerRegistry::class)->submit($occupancy, $occupancy->people()->where('role', 'viajero')->get());
        if ($result['status'] === 'incompleto') {
            throw new RentalRefused(__('clarto.alquileres.errors.travellers_incomplete', ['missing' => implode(', ', $result['missing'])]));
        }
        Occupancies::start($occupancy, $on, 'checkin');
        Occupancies::record($occupancy, 'ses_hospedajes', ['evento' => 'entrada'] + $result + ['fecha' => CarbonImmutable::parse($on)->toDateString()]);

        return $result;
    }

    /** Check-out: dates freed and a cleaning job opened for the unit. */
    public static function checkOut(Occupancy $occupancy, DateTimeInterface|string $on): RentalIncident
    {
        Occupancies::finish($occupancy, $on, 'fin_estancia', 'checkout');

        return self::openIncident($occupancy->refresh(), ['kind' => 'limpieza', 'title' => __('clarto.alquileres.cleaning_title'), 'priority' => 'alta', 'access_authorized' => true, 'cost_bearer' => 'propietario', 'reported_on' => CarbonImmutable::parse($on)->toDateString()]);
    }

    /**
     * Owner settlement: collected income − refunds − CLARTO commission − owner-borne costs. The commission goes to
     * invoicing as an albarán.
     *
     * @return array{ingresos: float, devoluciones: float, comision: float, gastos: float, neto: float, albaran: ?string}
     */
    public static function settleOwner(Occupancy $occupancy): array
    {
        $settings = RentalSettings::forTeam($occupancy->team_id);
        $income = (float) $occupancy->charges()->where('status', 'cobrado')->whereIn('concept', ['tarifa', 'limpieza', 'renta'])->sum('amount');
        $refunds = (float) $occupancy->charges()->where('concept', 'devolucion')->where('payer', 'clarto')->sum('amount');
        $costs = (float) $occupancy->incidents()->where('status', 'cerrada')->where('cost_bearer', 'propietario')->sum('final_amount');
        $commission = round(($income - $refunds) * (float) $settings['comision_vacacional_pct'] / 100, 2);
        $net = round($income - $refunds - $commission - $costs, 2);
        $owner = $occupancy->people()->where('role', 'propietario')->first();
        $albaran = $commission > 0
            ? self::requestAlbaran($occupancy, [['description' => __('clarto.alquileres.concepts.comision'), 'quantity' => 1, 'unit_price' => $commission, 'tipo' => 'comision']], 'propietario', $owner?->party_id)->albaran_ref
            : null;
        self::charge($occupancy, 'liquidacion_propietario', $net, now(), 'clarto', null, __('clarto.alquileres.concepts.liquidacion_propietario'), compact('income', 'refunds', 'commission', 'costs'));
        $result = ['ingresos' => $income, 'devoluciones' => $refunds, 'comision' => $commission, 'gastos' => $costs, 'neto' => $net, 'albaran' => $albaran];
        Occupancies::record($occupancy, 'liquidacion_propietario', $result);

        return $result;
    }

    private static function charge(Occupancy $occupancy, string $concept, float $amount, DateTimeInterface|string $due, string $payer, ?string $period, ?string $description, array $data = []): RentalCharge
    {
        return $occupancy->charges()->create([
            'team_id' => $occupancy->team_id, 'concept' => $concept, 'amount' => $amount, 'due_on' => CarbonImmutable::parse($due),
            'payer' => $payer, 'period' => $period, 'description' => $description, 'data' => $data ?: null, 'status' => 'pendiente',
        ]);
    }
}
