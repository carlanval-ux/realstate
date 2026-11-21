<?php

declare(strict_types=1);

namespace App\WhatsApp;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * WAHA HTTP API (internal service, X-Api-Key). Only the calls the panel needs: session lifecycle, QR, send text,
 * and resolving a WhatsApp LID to its phone number.
 */
final class Waha
{
    public const EVENTS = ['message', 'message.ack', 'session.status'];

    /** @return array<string, mixed>|null null when the session does not exist in WAHA */
    public function session(string $session): ?array
    {
        $response = $this->http()->get('/api/sessions/'.rawurlencode($session));

        return $response->status() === 404 ? null : $response->throw()->json();
    }

    /** Creates the session (or refreshes its webhook config when stopped) and starts it. */
    public function start(string $session): void
    {
        $current = $this->session($session);
        $body = ['name' => $session, 'config' => ['webhooks' => [[
            'url' => (string) config('whatsapp.webhook_url'),
            'events' => self::EVENTS,
            'hmac' => ['key' => (string) config('whatsapp.webhook_hmac_key')],
            'retries' => ['policy' => 'exponential', 'delaySeconds' => 2, 'attempts' => 6],
        ]]]];

        if ($current === null) {
            $this->http()->post('/api/sessions', $body + ['start' => true])->throw();

            return;
        }
        if (in_array($current['status'] ?? '', ['STOPPED', 'FAILED'], true)) {
            $this->http()->put('/api/sessions/'.rawurlencode($session), $body)->throw();
            $this->http()->post('/api/sessions/'.rawurlencode($session).'/start')->throw();
        }
    }

    public function restart(string $session): void
    {
        $this->http()->post('/api/sessions/'.rawurlencode($session).'/restart')->throw();
    }

    /** Unlinks the device from the phone (WhatsApp → Linked devices) and stops the session. */
    public function logout(string $session): void
    {
        $this->http()->post('/api/sessions/'.rawurlencode($session).'/logout')->throw();
        $this->http()->post('/api/sessions/'.rawurlencode($session).'/stop');
    }

    /** @return string|null data URI of the QR image, fetched server-side (never exposed to the browser by URL) */
    public function qr(string $session): ?string
    {
        $response = $this->http()->accept('application/json')->get('/api/'.rawurlencode($session).'/auth/qr');
        if (! $response->successful()) {
            return null;
        }
        // WAHA may answer with the image itself instead of {mimetype, data}: accept both.
        $type = strtolower(trim((string) strtok((string) $response->header('Content-Type'), ';')));
        if (str_starts_with($type, 'image/')) {
            return $response->body() === '' ? null : 'data:'.$type.';base64,'.base64_encode($response->body());
        }
        if (blank($response->json('data'))) {
            return null;
        }

        return 'data:'.($response->json('mimetype') ?: 'image/png').';base64,'.$response->json('data');
    }

    /** @return array<string, mixed> the message WAHA created */
    public function sendText(string $session, string $phone, string $text): array
    {
        return (array) $this->http()->post('/api/sendText', [
            'session' => $session, 'chatId' => ltrim($phone, '+').'@c.us', 'text' => $text,
        ])->throw()->json();
    }

    /** Phone (E.164) behind a WhatsApp LID ("123@lid"), when WAHA knows it. */
    public function phoneForLid(string $session, string $lid): ?string
    {
        try {
            $pn = (string) $this->http()->get('/api/'.rawurlencode($session).'/lids/'.rawurlencode($lid))->throw()->json('pn');
        } catch (RequestException) {
            return null;
        }

        return Phone::fromChatId($pn);
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl((string) config('whatsapp.url'))
            ->withHeaders(['X-Api-Key' => (string) config('whatsapp.api_key')])
            ->acceptJson()->asJson()->connectTimeout(5)->timeout(20);
    }
}
