<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Outgoing mail configured from the panel (Platform settings → Outgoing mail). When enabled it replaces the
 * server's MAIL_* defaults (the test mailbox, or scripts/set-smtp.sh) for every e-mail the panel sends.
 */
final class MailSetting extends Model
{
    protected $table = 'clarto_mail_settings';

    protected $guarded = ['id'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'password' => 'encrypted', 'port' => 'integer'];
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate([], [
            'host' => 'smtp.hostinger.com',
            'port' => 465,
            'encryption' => 'ssl',
            // Pre-filled with the mailbox the server already uses, if any (never its password).
            'username' => config('mail.mailers.smtp.username'),
            'from_address' => config('mail.from.address'),
            'from_name' => config('app.name'),
        ]);
    }

    /** Applies the saved settings to this process (called at boot and after saving). */
    public static function apply(): void
    {
        try {
            $s = self::query()->first();
        } catch (Throwable) {
            return; // table not migrated yet
        }
        if ($s === null || ! $s->enabled || blank($s->host)) {
            return;
        }

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => $s->host,
            'mail.mailers.smtp.port' => $s->port,
            'mail.mailers.smtp.scheme' => $s->encryption === 'ssl' ? 'smtps' : 'smtp',
            'mail.mailers.smtp.username' => $s->username,
            'mail.mailers.smtp.password' => $s->password,
            'mail.from.address' => $s->from_address ?: $s->username,
            'mail.from.name' => $s->from_name ?: config('app.name'),
        ]);
        Mail::purge('smtp');
    }
}
