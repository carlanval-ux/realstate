<?php

declare(strict_types=1);

namespace App\Communications;

use App\Models\Communication;
use App\Models\WhatsAppLine;
use App\Signing\SmsGateway;
use App\WhatsApp\Phone;
use App\WhatsApp\Waha;
use App\WhatsApp\WhatsApp;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Liberu\RealEstate\Parties\Models\Party;
use RuntimeException;
use Throwable;

/**
 * Communications with clients, GoHighLevel-Conversations style: one log (clarto_communications) that every channel
 * and every module writes to, one chronological timeline per client.
 *
 * - send(): WhatsApp (company line, WAHA), SMS (App\Signing\SmsGateway: simulated until the provider is accredited,
 *   and only to test destinations while FIRMA_SMS_ONLY_TEST_DESTINATIONS is on) or e-mail (the panel's mailbox).
 *   Invalid input is refused (exception); a delivery problem is logged as «Not sent» and returned.
 * - record(): something another module already sent (signature SMS, system e-mails).
 *
 * Team isolation: rows carry team_id and only the team's contacts are linked. Purpose: service always; marketing only
 * when the contact gave consent (Party::consent_at). Idempotency: one send per key.
 */
final class Communications
{
    /** Header that marks the panel's own e-mails, so the system-mail listener does not log them twice. */
    public const MAIL_HEADER = 'X-Clarto-Communication';

    /**
     * @param  array{key?: string, subject?: ?string, user_id?: ?int, purpose?: string, source?: string, related?: ?Model, instruction_id?: ?int}  $o
     *
     * @throws RuntimeException invalid recipient, empty text, unfilled {placeholder}, no consent for marketing
     */
    public static function send(int|string $teamId, ?Party $party, string $channel, string $to, string $body, array $o = [], ?Waha $waha = null): Communication
    {
        $purpose = $o['purpose'] ?? 'servicio';
        $body = trim($body);
        $subject = trim((string) ($o['subject'] ?? ''));
        throw_unless(isset(Communication::CHANNELS[$channel]) && in_array($purpose, Communication::PURPOSES, true), new RuntimeException(__('Unknown channel or purpose.')));
        throw_if($party !== null && (string) $party->team_id !== (string) $teamId, new RuntimeException(__('This contact belongs to another team.')));
        throw_if($body === '', new RuntimeException(__('The message is empty.')));
        if (preg_match('/\{[a-z_]+\}/', $body.' '.$subject, $m)) {
            throw new RuntimeException(__('Complete :field before sending.', ['field' => $m[0]]));
        }
        if ($purpose === 'marketing' && blank($party?->consent_at) && ! ($o['consent_verified'] ?? false)) {   // GHL W2: consent checked in GHL
            throw new RuntimeException(__('This contact has not consented to commercial messages: only service messages can be sent.'));
        }

        $address = ['phone' => null, 'email' => null];
        if ($channel === 'email') {
            $address['email'] = filter_var(Str::lower(trim($to)), FILTER_VALIDATE_EMAIL) ?: throw new RuntimeException(__('The recipient is not a valid e-mail address.'));
            throw_if($subject === '', new RuntimeException(__('The e-mail needs a subject.')));
        } else {
            $address['phone'] = Phone::e164($to) ?? throw new RuntimeException(__('The recipient is not a valid phone number.'));
        }
        if ($channel === 'sms' && mb_strlen($body) > 160) {
            throw new RuntimeException(__('An SMS has at most 160 characters (this one has :n).', ['n' => mb_strlen($body)]));
        }

        $key = $o['key'] ?? (string) Str::uuid();

        return Cache::lock('comunicacion:'.$key, 30)->block(10, function () use ($teamId, $party, $channel, $body, $subject, $address, $purpose, $key, $o, $waha): Communication {
            $c = Communication::query()->firstOrNew(['idempotency_key' => $key]);
            if ($c->exists && $c->status !== 'error') {
                return $c;   // already sent (or being sent) with this key: never twice
            }
            $c->fill($address + [
                'team_id' => $teamId, 'party_id' => $party?->getKey(), 'channel' => $channel, 'direction' => Communication::OUT,
                'subject' => $subject ?: null, 'body' => $body, 'purpose' => $purpose, 'source' => $o['source'] ?? 'panel',
                'related_type' => isset($o['related']) ? $o['related']->getMorphClass() : null, 'related_id' => isset($o['related']) ? (string) $o['related']->getKey() : null,
                'instruction_id' => $o['instruction_id'] ?? null, 'user_id' => $o['user_id'] ?? null, 'status' => 'pendiente', 'error' => null,
            ])->save();

            try {
                [$ok, $providerId, $error] = match ($channel) {
                    'whatsapp' => self::viaWhatsApp($c, $waha ?? new Waha),
                    'sms' => self::viaSms($c),
                    'email' => self::viaEmail($c),
                };
            } catch (Throwable $e) {
                report($e);
                [$ok, $providerId, $error] = [false, null, $e->getMessage()];
            }

            $c->fill($ok
                ? ['status' => 'enviado', 'sent_at' => now(), 'provider_id' => $providerId]
                : ['status' => 'error', 'error' => mb_strimwidth((string) $error, 0, 250, '…')])->save();
            if ($ok && $party !== null && $c->phone !== null) {
                WhatsApp::rememberLink($teamId, $c->phone, (int) $party->getKey());   // replies from this number → this client
            }

            return $c;
        });
    }

    /**
     * Logs a communication another module already sent (or received). Never throws: logging must not break it.
     *
     * @param  array<string, mixed>  $attributes  phone|email, body, status, subject, purpose, source, provider_id, error, user_id, related (Model), party_id
     */
    public static function record(int|string $teamId, string $channel, string $direction, array $attributes): ?Communication
    {
        try {
            $related = $attributes['related'] ?? null;
            unset($attributes['related']);
            $phone = Phone::e164($attributes['phone'] ?? null);
            $email = filled($attributes['email'] ?? null) ? Str::lower((string) $attributes['email']) : null;

            return Communication::query()->create(array_merge(
                ['status' => 'enviado', 'sent_at' => now(), 'idempotency_key' => 'rec:'.Str::uuid(),
                    'party_id' => array_key_exists('party_id', $attributes) ? $attributes['party_id'] : self::partyFor($teamId, $phone, $email)],
                $attributes,
                ['team_id' => $teamId, 'channel' => $channel, 'direction' => $direction, 'phone' => $phone, 'email' => $email,
                    'related_type' => $related?->getMorphClass(), 'related_id' => $related ? (string) $related->getKey() : null],
            ));
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /** The team's only contact with this phone or e-mail (null when none or ambiguous). */
    public static function partyFor(int|string $teamId, ?string $phone, ?string $email = null): ?int
    {
        if ($phone !== null) {
            [$partyId] = WhatsApp::match($teamId, $phone);
            if ($partyId !== null) {
                return $partyId;
            }
        }
        if (filled($email)) {
            $ids = Party::query()->forTeam($teamId)->whereRaw('lower(email) = ?', [Str::lower($email)])->pluck('id');

            return $ids->count() === 1 ? (int) $ids->first() : null;
        }

        return null;
    }

    /** @return Collection<int, Communication> the client's timeline, oldest first */
    public static function timeline(Party $party, int $limit = 200): Collection
    {
        return Communication::query()->with('author:id,name')->where('team_id', $party->team_id)->where('party_id', $party->getKey())
            ->latest('id')->limit($limit)->get()->reverse()->values();
    }

    public static function markRead(Party $party): void
    {
        Communication::query()->where('team_id', $party->team_id)->where('party_id', $party->getKey())
            ->where('direction', Communication::IN)->whereNull('read_at')->update(['read_at' => now()]);
    }

    /**
     * Inbox: the team's clients with communications, most recent first, with their last message and unread count.
     *
     * @return Collection<int, Communication> each with party_name and unread
     */
    public static function inbox(int|string $teamId, ?string $search = null, int $limit = 100): Collection
    {
        $last = Communication::query()->selectRaw('party_id, max(id) as last_id, sum(case when direction = ? and read_at is null then 1 else 0 end) as unread', [Communication::IN])
            ->where('team_id', $teamId)->whereNotNull('party_id')->groupBy('party_id');

        return Communication::query()
            ->joinSub($last, 'l', 'l.last_id', '=', 'clarto_communications.id')
            ->join('real_estate_parties as p', 'p.id', '=', 'l.party_id')
            ->where('p.team_id', $teamId)->whereNull('p.deleted_at')
            ->when(filled($search), fn ($q) => $q->where(fn ($q) => $q->where('p.name', 'like', '%'.$search.'%')->orWhere('p.phone', 'like', '%'.$search.'%')->orWhere('p.email', 'like', '%'.$search.'%')))
            ->orderByDesc('l.last_id')->limit($limit)
            ->get(['clarto_communications.*', 'l.unread', 'p.name as party_name']);
    }

    public static function unreadCount(int|string $teamId): int
    {
        return Communication::query()->where('team_id', $teamId)->whereNotNull('party_id')->where('direction', Communication::IN)->whereNull('read_at')->count();
    }

    /** System e-mails (signature requests, notices…) to a client's address are logged on the client: subject only. */
    public static function logSystemMail(MessageSent $event): void
    {
        $message = $event->message;
        if ($message->getHeaders()->has(self::MAIL_HEADER)) {
            return;   // sent by send(): already logged
        }
        $teamId = auth()->user()?->current_team_id;
        foreach ($message->getTo() as $to) {
            $email = Str::lower($to->getAddress());
            $parties = Party::query()->whereRaw('lower(email) = ?', [$email])->when($teamId, fn ($q) => $q->where('team_id', $teamId))->get(['id', 'team_id']);
            // ponytail: without a signed-in team, only an address that belongs to a single team is logged (no cross-team leaks)
            if ($parties->pluck('team_id')->unique()->count() !== 1) {
                continue;
            }
            self::record($parties->first()->team_id, 'email', Communication::OUT, [
                'email' => $email, 'subject' => mb_strimwidth((string) $message->getSubject(), 0, 250, '…'),
                'body' => __('System e-mail sent by the panel.'), 'source' => 'sistema',
                'party_id' => $parties->count() === 1 ? $parties->first()->id : null,
            ]);
        }
    }

    /** @return array{0: bool, 1: ?string, 2: ?string} */
    private static function viaWhatsApp(Communication $c, Waha $waha): array
    {
        $line = WhatsAppLine::forTeam($c->team_id);
        if (! $line->connected() && ! $line->refreshFromWaha($waha)->connected()) {
            return [false, null, __('The WhatsApp line is not connected (:status). An administrator can link it in Platform settings → WhatsApp.', ['status' => $line->statusLabel()])];
        }
        $sent = $waha->sendText($line->session, $c->phone, $c->body);

        return [true, WhatsApp::messageId($sent), null];
    }

    /** @return array{0: bool, 1: ?string, 2: ?string} */
    private static function viaSms(Communication $c): array
    {
        $to = ltrim((string) $c->phone, '+');
        if (config('firma.sms.only_test_destinations', true) && ! in_array($to, (array) config('firma.sms.test_destinations'), true)) {
            return [false, null, __('Not a test destination: real SMS to customers are not enabled yet.')];
        }
        $r = app(SmsGateway::class)->send($to, $c->body, 'com-'.$c->getKey());

        return [$r['status'] === 'aceptado', $r['id'] ?? null, $r['error'] ?? null];
    }

    /** @return array{0: bool, 1: ?string, 2: ?string} */
    private static function viaEmail(Communication $c): array
    {
        Mail::raw($c->body, function ($m) use ($c): void {
            $m->to($c->email)->subject($c->subject);
            $m->getSymfonyMessage()->getHeaders()->addTextHeader(self::MAIL_HEADER, (string) $c->getKey());
        });

        return [true, null, null];
    }
}
