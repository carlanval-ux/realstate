<?php

declare(strict_types=1);

namespace App\WhatsApp;

/** Phone numbers as E.164 ("+34600000001"), the key that links WhatsApp conversations to contacts. */
final class Phone
{
    /** "600 00 00 01", "0034 600…", "+34-600…" → "+34600000001"; null when it cannot be a phone number. */
    public static function e164(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits === '') {
            return null;
        }

        if (str_starts_with($raw, '+')) {
            // already international
        } elseif (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        } elseif (strlen($digits) === 9) {
            // ponytail: national numbers assume the default country (Spain, 9 digits); other countries need the "+" prefix
            $digits = config('whatsapp.default_country_code', '34').$digits;
        }

        return strlen($digits) >= 8 && strlen($digits) <= 15 ? '+'.$digits : null;
    }

    /** "34600000001@c.us" / "@s.whatsapp.net" → "+34600000001"; groups, LIDs, broadcasts → null. */
    public static function fromChatId(?string $chatId): ?string
    {
        if (! preg_match('/^(\d+)(?::\d+)?@(c\.us|s\.whatsapp\.net)$/', (string) $chatId, $m)) {
            return null;
        }

        return self::e164('+'.$m[1]);
    }
}
