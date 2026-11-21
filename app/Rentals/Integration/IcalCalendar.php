<?php

declare(strict_types=1);

namespace App\Rentals\Integration;

use App\Rentals\Models\Occupancy;
use App\Rentals\Models\RentalUnit;
use App\Rentals\Occupancies;
use App\Rentals\OccupancyConflict;
use Carbon\CarbonImmutable;

/**
 * Channel calendar sync through iCal (the format Airbnb, Booking and Vrbo exchange):
 *  - export(): the unit's occupied nights as an .ics feed (real, standard; serve it at a secret URL when published);
 *  - import(): turns a channel's .ics into calendar blocks (kind «bloqueo», channel + UID kept, idempotent).
 * Fetching the channel URL is NOT done here (no channel URLs yet): pass the downloaded text. Status: prepared.
 */
final class IcalCalendar
{
    public static function export(RentalUnit $unit): string
    {
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//CLARTO//Alquileres//ES', 'CALSCALE:GREGORIAN'];
        $taken = Occupancy::query()->whereIn('unit_id', $unit->conflictingUnitIds())->whereIn('status', Occupancy::BLOCKING)->get();
        foreach ($taken as $o) {
            $lines = [...$lines, 'BEGIN:VEVENT', 'UID:clarto-'.$o->getKey().'@clarto.es', 'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z'),
                'DTSTART;VALUE=DATE:'.$o->start()->format('Ymd'), 'DTEND;VALUE=DATE:'.$o->end()->format('Ymd'), 'SUMMARY:No disponible', 'END:VEVENT'];
        }
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines)."\r\n";
    }

    /**
     * @return array{created: int, skipped: int, conflicts: list<string>}
     */
    public static function import(RentalUnit $unit, string $channel, string $ics): array
    {
        $result = ['created' => 0, 'skipped' => 0, 'conflicts' => []];
        $ics = preg_replace("/\r?\n[ \t]/", '', $ics); // unfold
        preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', (string) $ics, $events);
        foreach ($events[1] as $event) {
            preg_match('/^UID:(.+)$/m', $event, $uid);
            preg_match('/^DTSTART[^:]*:(\d{8})/m', $event, $start);
            preg_match('/^DTEND[^:]*:(\d{8})/m', $event, $end);
            if (! $uid || ! $start || ! $end || str_ends_with(trim($uid[1]), '@clarto.es')) {
                $result['skipped']++;

                continue;
            }
            $ref = trim($uid[1]);
            if (Occupancy::query()->where('unit_id', $unit->getKey())->where('channel', $channel)->where('channel_ref', $ref)->exists()) {
                $result['skipped']++;

                continue;
            }
            try {
                Occupancies::book(['unit_id' => $unit->getKey(), 'module' => 'vacacional', 'kind' => 'bloqueo', 'channel' => $channel, 'channel_ref' => $ref,
                    'planned_start' => CarbonImmutable::createFromFormat('Ymd', $start[1])->startOfDay(), 'planned_end' => CarbonImmutable::createFromFormat('Ymd', $end[1])->startOfDay(),
                    'notes' => 'iCal '.$channel], [], null, $unit);
                $result['created']++;
            } catch (OccupancyConflict $e) {
                $result['conflicts'][] = $ref.': '.$e->getMessage();
            }
        }

        return $result;
    }
}
