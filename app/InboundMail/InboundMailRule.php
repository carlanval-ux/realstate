<?php

declare(strict_types=1);

namespace App\InboundMail;

use Illuminate\Database\Eloquent\Model;

/** A staff correction turned into a rule: this sender (or this whole domain) is always this category / area. */
final class InboundMailRule extends Model
{
    public const SENDER = 'remitente';

    public const DOMAIN = 'dominio';

    /** Domains shared by millions of people: a rule for them is per sender, never per domain. */
    public const PUBLIC_DOMAINS = ['gmail.com', 'googlemail.com', 'hotmail.com', 'hotmail.es', 'outlook.com', 'outlook.es', 'live.com', 'yahoo.com', 'yahoo.es', 'icloud.com', 'me.com', 'msn.com', 'aol.com', 'gmx.com', 'gmx.es', 'proton.me', 'protonmail.com', 'telefonica.net', 'movistar.es'];

    protected $table = 'clarto_inbound_mail_rules';

    protected $guarded = ['id'];

    public static function match(int|string $teamId, ?string $email): ?self
    {
        if (blank($email)) {
            return null;
        }
        $email = strtolower($email);

        return self::query()->where('team_id', $teamId)->where('kind', self::SENDER)->where('value', $email)->first()
            ?? self::query()->where('team_id', $teamId)->where('kind', self::DOMAIN)->where('value', substr(strrchr($email, '@') ?: '', 1))->first();
    }
}
