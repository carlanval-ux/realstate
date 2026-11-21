<?php

declare(strict_types=1);

namespace App\SearchRequests;

use Illuminate\Database\Eloquent\Model;

/** Per company: default deadline (21 days), reminders (7 and 2 days before), notice to the owner, time zone. */
final class SearchRequestSettings extends Model
{
    public const DEFAULTS = ['default_days' => 21, 'reminder_days' => [7, 2], 'owner_notice_days' => 3, 'timezone' => 'Europe/Madrid'];

    protected $table = 'clarto_search_request_settings';

    protected $primaryKey = 'team_id';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['reminder_days' => 'array'];
    }

    public static function forTeam(int|string|null $teamId): self
    {
        $settings = $teamId === null ? null : self::query()->find($teamId);

        return $settings ?? new self(['team_id' => $teamId] + self::DEFAULTS);
    }

    /** @return list<int> distinct days before the deadline, largest first */
    public function reminders(): array
    {
        $days = array_values(array_unique(array_filter(array_map('intval', (array) ($this->reminder_days ?? self::DEFAULTS['reminder_days'])), fn (int $d) => $d > 0)));
        rsort($days);

        return $days;
    }

    public function tz(): string
    {
        return in_array($this->timezone, timezone_identifiers_list(), true) ? $this->timezone : self::DEFAULTS['timezone'];
    }
}
