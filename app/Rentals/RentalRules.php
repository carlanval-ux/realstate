<?php

declare(strict_types=1);

namespace App\Rentals;

use App\Rentals\Models\Occupancy;
use App\Rentals\Models\RentalLicence;
use App\Rentals\Models\RentalSettings;
use App\Rentals\Models\RentalUnit;
use Carbon\CarbonImmutable;

/**
 * Legal rules turned into validations, as checked on 2026-11-21 (LAU consolidated to 13/11/2026 incl. RDL 29/2026;
 * Ley 15/2018 + DL 9/2024 CV; Decreto 46/2022 CV; RD 933/2021). Sources: docs/clarto/alquileres.md («Normativa»).
 * Errors block the operation; warnings are stored in the history so the agent sees them.
 */
final class RentalRules
{
    /** RDL 29/2026 in force: «vivienda temporal» (incl. rooms) is a dwelling lease from this day on (LAU art. 2.3, DT 8.ª). */
    public const REFORM_DATE = '2026-10-08';

    /** LAU art. 36.1: fianza = 1 month (vivienda, incl. vivienda temporal) / 2 months (uso distinto: temporada signed before the reform). */
    public const DEPOSIT_MONTHS = ['habitual' => 1, 'temporal' => 1, 'temporada_anterior' => 2];

    /** LAU art. 36.5: additional guarantee at most 2 months (habitual) / 1 month (vivienda temporal). */
    public const EXTRA_GUARANTEE_MAX_MONTHS = ['habitual' => 2, 'temporal' => 1];

    /** LAU art. 36.4: the fianza is returned within one month of handing back the keys (legal interest after). */
    public const DEPOSIT_REFUND_DAYS = 30;

    /** Decreto 46/2022 CV art. 3.1: lodge the fianza within one month of the contract (or of the actual start). */
    public const DEPOSIT_LODGE_MONTHS = 1;

    /** LAU art. 9.1: prórroga obligatoria up to 5 years (7 if the landlord is a legal person). */
    public const MIN_YEARS = ['persona_fisica' => 5, 'persona_juridica' => 7];

    /** LAU art. 9 bis.1: vivienda temporal lasts more than 31 days and, as a rule, up to 12 months. */
    public const TEMPORARY_MIN_DAYS = 31;

    public const TEMPORARY_MAX_MONTHS = 12;

    /** Ley 15/2018 CV art. 65.1: VUT = whole dwelling, 10 consecutive days or fewer to the same guest. */
    public const VUT_MAX_NIGHTS = 10;

    /** LAU art. 20.2: services a tenant can never be charged for (preparation, formalisation, management, novation, renewal). */
    public const LANDLORD_FEES = ['gestion', 'formalizacion', 'preparacion', 'novacion', 'renovacion'];

    /** Module → allowed purposes and unit kind. */
    private const SHAPES = [
        'tradicional' => ['purposes' => ['habitual', 'temporal'], 'unit' => RentalUnit::DWELLING],
        'habitaciones' => ['purposes' => ['habitual', 'temporal'], 'unit' => RentalUnit::ROOM],
        'vacacional' => ['purposes' => ['turistica'], 'unit' => RentalUnit::DWELLING],
    ];

    /** @return array{errors: list<string>, warnings: list<string>} */
    public static function check(Occupancy $occupancy, RentalUnit $unit): array
    {
        $errors = [];
        $warnings = [];
        $shape = self::SHAPES[$occupancy->module] ?? null;
        if ($shape === null || ! in_array($occupancy->kind, Occupancy::KINDS, true)) {
            return ['errors' => [__('clarto.alquileres.errors.module_kind')], 'warnings' => []];
        }
        if ($occupancy->planned_start === null || $occupancy->planned_end === null || $occupancy->planned_end->lte($occupancy->planned_start)) {
            return ['errors' => [__('clarto.alquileres.errors.dates')], 'warnings' => []];
        }
        if ((int) $unit->property_id !== (int) $occupancy->property_id) {
            $errors[] = __('clarto.alquileres.errors.unit_property');
        }
        if ($unit->kind !== $shape['unit']) {
            $errors[] = __('clarto.alquileres.errors.unit_kind_'.$occupancy->module);
        }
        if ($occupancy->kind === 'bloqueo') {
            return ['errors' => $errors, 'warnings' => $warnings]; // owner/maintenance block: no guests, no purpose
        }
        if (! in_array($occupancy->purpose, $shape['purposes'], true)) {
            $errors[] = __('clarto.alquileres.errors.purpose_'.$occupancy->module);
        }
        if ($occupancy->occupants < 1 || $occupancy->occupants > $unit->max_occupants) {
            $errors[] = __('clarto.alquileres.errors.capacity', ['max' => $unit->max_occupants]);
        }
        if ($errors !== []) {
            return ['errors' => $errors, 'warnings' => $warnings];
        }
        $start = $occupancy->planned_start;
        $end = $occupancy->planned_end;

        if ($occupancy->purpose === 'temporal') {
            // LAU art. 7.2: the cause must be in the contract (else it is a habitual lease, retroactively).
            if (blank($occupancy->temporal_cause)) {
                $errors[] = __('clarto.alquileres.errors.temporal_cause');
            }
            if ($start->diffInDays($end) <= self::TEMPORARY_MIN_DAYS || $start->addMonths(self::TEMPORARY_MAX_MONTHS)->lt($end)) {
                $errors[] = __('clarto.alquileres.errors.temporal_length', ['min' => self::TEMPORARY_MIN_DAYS, 'max' => self::TEMPORARY_MAX_MONTHS]);
            }
        }
        if ($occupancy->purpose === 'habitual') {
            $years = self::MIN_YEARS[$occupancy->landlord_is_company ? 'persona_juridica' : 'persona_fisica'];
            if ($start->addYears($years)->gt($end)) {
                $warnings[] = __('clarto.alquileres.warnings.mandatory_extension', ['years' => $years]);
            }
        }
        if ($occupancy->module === 'habitaciones') {
            [$roomErrors, $roomWarnings] = self::roomRentCap($occupancy, $unit);
            $errors = [...$errors, ...$roomErrors];
            $warnings = [...$warnings, ...$roomWarnings];
        }
        if ($occupancy->purpose === 'turistica') {
            $errors = [...$errors, ...self::tourismErrors($occupancy)];
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /** Legal fianza in euros (0 when the LAU fianza does not apply, e.g. tourist stays). */
    public static function legalDeposit(Occupancy $occupancy): float
    {
        return $occupancy->rent_period === 'mes' ? round(self::depositMonths($occupancy) * (float) $occupancy->rent_amount, 2) : 0.0;
    }

    public static function depositMonths(Occupancy $occupancy): int
    {
        if ($occupancy->purpose === 'temporal' && $occupancy->planned_start->lt(CarbonImmutable::parse(self::REFORM_DATE))) {
            return self::DEPOSIT_MONTHS['temporada_anterior']; // DT 8.ª: earlier seasonal leases stay «uso distinto»
        }

        return self::DEPOSIT_MONTHS[$occupancy->purpose] ?? 0;
    }

    public static function depositRefundDeadline(CarbonImmutable $keysReturned): CarbonImmutable
    {
        return $keysReturned->addDays(self::DEPOSIT_REFUND_DAYS);
    }

    /** Comunitat Valenciana (Decreto 46/2022): the landlord lodges the full legal fianza (modelo 816/806 = resguardo). Ley 8/2004 CV art. 22: surcharge for late lodging (5 % ≤3 months late, 10 % ≤6, 15 % ≤12, 20 % + interest beyond). */
    public static function lateLodgingSurcharge(Occupancy $occupancy, CarbonImmutable $lodgedOn): int
    {
        $deadline = $occupancy->start()->min($occupancy->created_at ? CarbonImmutable::parse($occupancy->created_at)->startOfDay() : $occupancy->start())->addMonths(self::DEPOSIT_LODGE_MONTHS);
        if ($lodgedOn->lte($deadline)) {
            return 0;
        }
        $late = $deadline->diffInMonths($lodgedOn);

        return match (true) { $late <= 3 => 5, $late <= 6 => 10, $late <= 12 => 15, default => 20 };
    }

    /**
     * LAU art. 17.9: the rents of the rooms let at the same time cannot exceed the rent of the whole dwelling
     * (the dwelling unit's reference_rent). The occupancy being replaced by a room change is not counted twice.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function roomRentCap(Occupancy $occupancy, RentalUnit $room): array
    {
        $dwelling = RentalUnit::on($occupancy->getConnectionName())->find($room->parent_id);
        if ($dwelling === null || $dwelling->reference_rent === null) {
            return [[], [__('clarto.alquileres.warnings.no_reference_rent')]];
        }
        $format = $occupancy->getDateFormat();
        $others = Occupancy::on($occupancy->getConnectionName())
            ->whereIn('unit_id', $dwelling->rooms()->pluck('id'))
            ->whereIn('status', Occupancy::BLOCKING)
            ->where('rent_period', 'mes')
            ->when($occupancy->exists, fn ($q) => $q->whereKeyNot($occupancy->getKey()))
            ->when($occupancy->previous_occupancy_id, fn ($q, $id) => $q->whereKeyNot($id))
            ->whereRaw('COALESCE(actual_start, planned_start) < ?', [$occupancy->planned_end->format($format)])
            ->whereRaw('COALESCE(actual_end, planned_end) > ?', [$occupancy->planned_start->format($format)])
            ->sum('rent_amount');
        $total = (float) $others + (float) $occupancy->rent_amount;

        return $total > (float) $dwelling->reference_rent + 0.009
            ? [[__('clarto.alquileres.errors.room_rent_cap', ['total' => number_format($total, 2, ',', '.'), 'max' => number_format((float) $dwelling->reference_rent, 2, ',', '.')])], []]
            : [[], []];
    }

    /**
     * Comunitat Valenciana VUT circuit: whole dwelling only (checked by the module shape), at most 10 consecutive
     * nights to the same guest, and a valid Registro de Turismo de la Comunitat Valenciana registration (5 years) for
     * the whole stay. The national Registro Único number is no longer required (STS 620/2026, 19/05/2026).
     *
     * @return list<string>
     */
    private static function tourismErrors(Occupancy $occupancy): array
    {
        if ((RentalSettings::forTeam($occupancy->team_id)['region'] ?? null) !== 'CV') {
            return [];
        }
        $errors = [];
        if ($occupancy->planned_start->diffInDays($occupancy->planned_end) > self::VUT_MAX_NIGHTS) {
            $errors[] = __('clarto.alquileres.errors.vut_max_nights', ['max' => self::VUT_MAX_NIGHTS]);
        }
        $lastNight = $occupancy->planned_end->subDay();
        $valid = RentalLicence::on($occupancy->getConnectionName())->where('property_id', $occupancy->property_id)->where('type', 'vut_cv')->get()
            ->first(fn (RentalLicence $l): bool => $l->isValidOn($occupancy->planned_start) && $l->isValidOn($lastNight));
        if ($valid === null) {
            $errors[] = __('clarto.alquileres.errors.licence_vut_cv');
        }

        return $errors;
    }
}
