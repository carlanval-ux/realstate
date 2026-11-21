<?php

declare(strict_types=1);

namespace App\InboundMail;

use Illuminate\Database\Eloquent\Model;

/**
 * Per company: polling on/off and interval, AI on/off, owner of each area (TARMIO tasks), retention; and the
 * IMAP cursor (UIDVALIDITY + last UID read). Only one company can poll the mailbox.
 */
final class InboundMailSettings extends Model
{
    public const DEFAULTS = ['polling' => false, 'interval_minutes' => 5, 'ai' => true, 'ai_model' => null, 'area_owners' => [], 'retention_days' => 730];

    /** Advertising and automatic messages are kept this long (days), whatever the retention. */
    public const SHORT_RETENTION = 30;

    protected $table = 'clarto_inbound_mail_settings';

    protected $primaryKey = 'team_id';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['polling' => 'boolean', 'ai' => 'boolean', 'area_owners' => 'array', 'last_poll_at' => 'datetime'];
    }

    public static function forTeam(int|string|null $teamId): self
    {
        $settings = $teamId === null ? null : self::query()->find($teamId);

        return $settings ?? new self(['team_id' => $teamId] + self::DEFAULTS);
    }

    public function ownerFor(?string $key): ?string
    {
        $owners = (array) $this->area_owners;

        return filled($owners[$key] ?? null) ? strtolower((string) $owners[$key]) : (filled($owners['otros'] ?? null) ? strtolower((string) $owners['otros']) : null);
    }
}
