<?php

declare(strict_types=1);

namespace App\Rentals;

use App\Rentals\Models\Occupancy;
use App\Rentals\Models\RentalEvent;
use App\Rentals\Models\RentalGuarantee;
use App\Rentals\Models\RentalUnit;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Calendar of the shared occupancy base. Every change that can occupy dates runs inside one transaction whose FIRST
 * statement writes the property's unit rows (lock_version + 1):
 *  - SQLite: that write takes the database write lock before anything is read, so two requests cannot both check
 *    and then both insert; the second waits (busy_timeout) and then sees the first, or gives up → OccupancyConflict.
 *  - MySQL / PostgreSQL: the same UPDATE row-locks the property's units until commit.
 * Then the overlap check runs against confirmed occupancies of the unit, its rooms (if it is the dwelling) or its
 * dwelling (if it is a room). Two different rooms of one dwelling never collide.
 */
final class Occupancies
{
    /**
     * Create a confirmed occupancy atomically (lock → rules → overlap → insert), with its people.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $people
     * @param  RentalUnit|null  $unit  the unit already loaded (else read from unit_id)
     */
    public static function book(array $attributes, array $people = [], ?string $connection = null, ?RentalUnit $unit = null): Occupancy
    {
        $unit ??= RentalUnit::on($connection)->findOrFail($attributes['unit_id']);
        RentalModules::ensureEnabled((string) ($attributes['module'] ?? ''));

        return self::locked((int) $unit->property_id, $connection, function (ConnectionInterface $db) use ($attributes, $people, $unit): Occupancy {
            $occupancy = (new Occupancy)->setConnection($db->getName())->fill(['unit_id' => $unit->getKey(), 'team_id' => $unit->team_id, 'property_id' => $unit->property_id] + $attributes);
            $occupancy->status = 'confirmada';
            $warnings = self::validate($occupancy, $unit);
            self::ensureFree($unit, $occupancy->planned_start, $occupancy->planned_end, null, $db->getName());
            $occupancy->save();
            foreach ($people as $person) {
                $occupancy->people()->create($person + ['team_id' => $occupancy->team_id]);
            }
            self::record($occupancy, 'alta', ['estado' => 'confirmada', 'avisos' => $warnings]);

            return $occupancy;
        });
    }

    /** Confirm a draft: from now on it blocks the calendar. */
    public static function confirm(Occupancy $occupancy): Occupancy
    {
        RentalModules::ensureEnabled($occupancy->module);

        return self::locked((int) $occupancy->property_id, $occupancy->getConnectionName(), function () use ($occupancy): Occupancy {
            $occupancy->refresh();
            self::ensureStatus($occupancy, ['borrador']);
            $warnings = self::validate($occupancy, $occupancy->unit);
            self::ensureFree($occupancy->unit, $occupancy->planned_start, $occupancy->planned_end, $occupancy->getKey(), $occupancy->getConnectionName());
            self::transition($occupancy, 'confirmada', 'confirmacion', ['avisos' => $warnings]);

            return $occupancy;
        });
    }

    /** Prórroga / renewal: same contract, later planned exit. */
    public static function extend(Occupancy $occupancy, DateTimeInterface|string $newEnd, string $type = 'prorroga'): Occupancy
    {
        RentalModules::ensureEnabled($occupancy->module);

        return self::locked((int) $occupancy->property_id, $occupancy->getConnectionName(), function () use ($occupancy, $newEnd, $type): Occupancy {
            $occupancy->refresh();
            self::ensureStatus($occupancy, ['confirmada', 'en_curso']);
            $to = CarbonImmutable::parse($newEnd)->startOfDay();
            $from = $occupancy->planned_end;
            if ($to->lte($from)) {
                throw new RentalRefused(__('clarto.alquileres.errors.extend_later'));
            }
            self::ensureFree($occupancy->unit, $from, $to, $occupancy->getKey(), $occupancy->getConnectionName());
            $occupancy->forceFill(['planned_end' => $to, 'renewals' => $occupancy->renewals + 1])->save();
            self::record($occupancy, $type, ['de' => $from->toDateString(), 'a' => $to->toDateString(), 'numero' => $occupancy->renewals]);

            return $occupancy;
        });
    }

    /** Keys handed over / check-in: the occupancy is running. */
    public static function start(Occupancy $occupancy, DateTimeInterface|string $on, string $type = 'entrega_llaves', array $data = []): Occupancy
    {
        return self::locked((int) $occupancy->property_id, $occupancy->getConnectionName(), function () use ($occupancy, $on, $type, $data): Occupancy {
            $occupancy->refresh();
            self::ensureStatus($occupancy, ['confirmada']);
            $day = CarbonImmutable::parse($on)->startOfDay();
            if ($day->lt($occupancy->planned_start)) {
                self::ensureFree($occupancy->unit, $day, $occupancy->planned_start, $occupancy->getKey(), $occupancy->getConnectionName());
            }
            $occupancy->actual_start = $day;
            self::transition($occupancy, 'en_curso', $type, $data + ['fecha' => $day->toDateString()]);

            return $occupancy;
        });
    }

    /** Exit / check-out: frees the dates from the actual exit on. A late exit is checked like any booking. */
    public static function finish(Occupancy $occupancy, DateTimeInterface|string $on, string $reason, string $type = 'salida', array $data = []): Occupancy
    {
        return self::locked((int) $occupancy->property_id, $occupancy->getConnectionName(), function () use ($occupancy, $on, $reason, $type, $data): Occupancy {
            $occupancy->refresh();
            self::ensureStatus($occupancy, ['confirmada', 'en_curso']);
            $day = CarbonImmutable::parse($on)->startOfDay();
            if ($day->lte($occupancy->start())) {
                throw new RentalRefused(__('clarto.alquileres.errors.exit_after_start'));
            }
            if ($day->gt($occupancy->planned_end)) {
                self::ensureFree($occupancy->unit, $occupancy->planned_end, $day, $occupancy->getKey(), $occupancy->getConnectionName());
            }
            $occupancy->actual_end = $day;
            $occupancy->end_reason = $reason;
            self::transition($occupancy, 'finalizada', $type, $data + ['fecha' => $day->toDateString(), 'motivo' => $reason]);

            return $occupancy;
        });
    }

    public static function cancel(Occupancy $occupancy, string $reason, array $data = []): Occupancy
    {
        return self::locked((int) $occupancy->property_id, $occupancy->getConnectionName(), function () use ($occupancy, $reason, $data): Occupancy {
            $occupancy->refresh();
            self::ensureStatus($occupancy, ['borrador', 'confirmada']);
            $occupancy->end_reason = $reason;
            self::transition($occupancy, 'cancelada', 'cancelacion', $data + ['motivo' => $reason]);

            return $occupancy;
        });
    }

    /**
     * Room change on $on: the current occupancy ends there (reason cambio_habitacion) and a new one in $to continues
     * until the same planned exit, linked by previous_occupancy_id, with the same people; guarantees follow the tenant.
     */
    public static function changeRoom(Occupancy $occupancy, RentalUnit $to, DateTimeInterface|string $on): Occupancy
    {
        RentalModules::ensureEnabled($occupancy->module);

        return self::locked((int) $occupancy->property_id, $occupancy->getConnectionName(), function () use ($occupancy, $to, $on): Occupancy {
            $occupancy->refresh();
            self::ensureStatus($occupancy, ['confirmada', 'en_curso']);
            $day = CarbonImmutable::parse($on)->startOfDay();
            if ((int) $to->property_id !== (int) $occupancy->property_id || $to->kind !== RentalUnit::ROOM || (int) $to->getKey() === (int) $occupancy->unit_id) {
                throw new RentalRefused(__('clarto.alquileres.errors.room_change_target'));
            }
            if ($day->lte($occupancy->start()) || $day->gte($occupancy->planned_end)) {
                throw new RentalRefused(__('clarto.alquileres.errors.room_change_date'));
            }
            self::ensureFree($to, $day, $occupancy->planned_end, $occupancy->getKey(), $occupancy->getConnectionName());

            $next = $occupancy->replicate(['actual_end', 'end_reason', 'renewals']);
            $next->fill(['unit_id' => $to->getKey(), 'planned_start' => $day, 'actual_start' => $occupancy->status === 'en_curso' ? $day : null, 'previous_occupancy_id' => $occupancy->getKey()]);
            self::validate($next, $to);
            $next->save();
            foreach ($occupancy->people as $person) {
                $copy = $person->replicate();
                $copy->occupancy_id = $next->getKey();
                $copy->save();
            }
            RentalGuarantee::on($occupancy->getConnectionName())->where('occupancy_id', $occupancy->getKey())->update(['occupancy_id' => $next->getKey()]);

            $occupancy->actual_end = $day;
            $occupancy->end_reason = 'cambio_habitacion';
            self::transition($occupancy, 'finalizada', 'cambio_habitacion', ['a_unidad' => $to->name, 'nueva_ocupacion' => $next->getKey(), 'fecha' => $day->toDateString()]);
            self::record($next, 'cambio_habitacion', ['de_unidad' => $occupancy->unit->name, 'ocupacion_anterior' => $occupancy->getKey(), 'fecha' => $day->toDateString()]);

            return $next;
        });
    }

    /** Confirmed occupancies colliding with [start, end) on this unit, its rooms or its dwelling. */
    public static function conflicts(RentalUnit $unit, DateTimeInterface $start, DateTimeInterface $end, ?int $ignoreId = null, ?string $connection = null): Collection
    {
        $format = (new Occupancy)->getDateFormat();

        return Occupancy::on($connection)
            ->whereIn('unit_id', $unit->conflictingUnitIds())
            ->whereIn('status', Occupancy::BLOCKING)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->whereRaw('COALESCE(actual_start, planned_start) < ?', [CarbonImmutable::instance($end)->startOfDay()->format($format)])
            ->whereRaw('COALESCE(actual_end, planned_end) > ?', [CarbonImmutable::instance($start)->startOfDay()->format($format)])
            ->get();
    }

    /** Append a line to the occupancy history. */
    public static function record(Occupancy $occupancy, string $type, array $data = [], DateTimeInterface|string|null $on = null): RentalEvent
    {
        return RentalEvent::on($occupancy->getConnectionName())->create([
            'team_id' => $occupancy->team_id, 'occupancy_id' => $occupancy->getKey(), 'type' => $type,
            'occurred_on' => CarbonImmutable::parse($on ?? $data['fecha'] ?? now())->toDateString(),
            'data' => array_filter($data, fn ($v): bool => $v !== null && $v !== []), 'user_id' => auth()->id(),
        ]);
    }

    /**
     * @template T
     *
     * @param  Closure(ConnectionInterface): T  $callback
     * @return T
     */
    private static function locked(int $propertyId, ?string $connection, Closure $callback): mixed
    {
        $db = DB::connection($connection);
        try {
            return $db->transaction(function () use ($db, $propertyId, $callback): mixed {
                if ($db->table('clarto_rent_units')->where('property_id', $propertyId)->increment('lock_version') === 0) {
                    throw new RentalRefused(__('clarto.alquileres.errors.no_units'));
                }

                return $callback($db);
            });
        } catch (QueryException $e) {
            // SQLite "database is locked" / MySQL lock wait timeout / PostgreSQL lock or serialization failure.
            if (preg_match('/database is locked|SQLSTATE\[HY000\]: General error: 5|Lock wait timeout|deadlock|could not serialize|40001|55P03/i', $e->getMessage())) {
                throw new OccupancyConflict(__('clarto.alquileres.errors.busy'), previous: $e);
            }
            throw $e;
        }
    }

    private static function ensureFree(RentalUnit $unit, DateTimeInterface $start, DateTimeInterface $end, ?int $ignoreId, ?string $connection): void
    {
        $taken = self::conflicts($unit, $start, $end, $ignoreId, $connection);
        if ($taken->isNotEmpty()) {
            $first = $taken->first();
            throw new OccupancyConflict(__('clarto.alquileres.errors.overlap', [
                'unit' => $first->unit?->name ?? '#'.$first->unit_id,
                'from' => $first->start()->format('d/m/Y'), 'to' => $first->end()->format('d/m/Y'),
            ]));
        }
    }

    /** @param list<string> $allowed */
    private static function ensureStatus(Occupancy $occupancy, array $allowed): void
    {
        if (! in_array($occupancy->status, $allowed, true)) {
            throw new RentalRefused(__('clarto.alquileres.errors.status', ['status' => __('clarto.alquileres.statuses.'.$occupancy->status)]));
        }
    }

    /** @return list<string> warnings */
    private static function validate(Occupancy $occupancy, RentalUnit $unit): array
    {
        if (! $unit->active || $unit->trashed()) {
            throw new RentalRefused(__('clarto.alquileres.errors.unit_inactive'));
        }
        $result = RentalRules::check($occupancy, $unit);
        if ($result['errors'] !== []) {
            throw new RentalRefused(implode(' ', $result['errors']));
        }

        return $result['warnings'];
    }

    private static function transition(Occupancy $occupancy, string $status, string $event, array $data = []): void
    {
        $from = $occupancy->status;
        $occupancy->status = $status;
        $occupancy->save();
        self::record($occupancy, $event, $data + ['de' => $from, 'a' => $status]);
    }
}
