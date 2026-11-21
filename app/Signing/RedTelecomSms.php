<?php

declare(strict_types=1);

namespace App\Signing;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Red Telecom dealer SMS (SmsEasySms white-label): POST JSON with HTTP Basic, `to[]` without "+", `text` ≤ 160,
 * `from` ≤ 11 alphanumeric, `reference`. 202/207 answer per recipient: [{"accepted":true,"to":"34…","id":"…"}];
 * errors {"error":{"code":102..113,"description":…}}; 402 = no credits. The provider also requires the server's
 * IP in its allow-list. There is no OTP endpoint.
 */
final class RedTelecomSms implements SmsGateway
{
    public function name(): string
    {
        return 'redtelecom';
    }

    public function send(string $to, string $text, string $reference): array
    {
        $user = (string) config('firma.sms.username');
        $password = (string) config('firma.sms.password');
        if ($user === '' || $password === '') {
            return ['status' => 'error', 'id' => null, 'error' => __('SMS is not configured on the server (dealer credentials missing).')];
        }

        try {
            $r = Http::withBasicAuth($user, $password)->acceptJson()->asJson()->timeout(20)
                ->post((string) config('firma.sms.url'), [
                    'to' => [$to], 'text' => $text, 'from' => mb_substr((string) config('firma.sms.sender'), 0, 11), 'reference' => $reference,
                ]);
        } catch (ConnectionException) {
            return ['status' => 'error', 'id' => null, 'error' => __('The SMS provider did not answer.')];
        }

        if ($r->status() === 402) {
            return ['status' => 'rechazado', 'id' => null, 'error' => __('SMS provider: not enough credits.')];
        }
        $json = $r->json();
        if (in_array($r->status(), [202, 207, 200], true) && is_array($json) && array_is_list($json)) {
            $row = collect($json)->firstWhere('to', $to) ?? $json[0] ?? [];

            return ($row['accepted'] ?? false)
                ? ['status' => 'aceptado', 'id' => isset($row['id']) ? (string) $row['id'] : null, 'error' => null]
                : ['status' => 'rechazado', 'id' => null, 'error' => 'SMS '.($row['error']['code'] ?? '?').': '.($row['error']['description'] ?? '')];
        }

        return ['status' => 'error', 'id' => null, 'error' => 'SMS HTTP '.$r->status().(isset($json['error']['code']) ? ' · '.$json['error']['code'].': '.($json['error']['description'] ?? '') : '')];
    }
}
