<?php

declare(strict_types=1);

namespace App\WhatsApp;

use App\Acquisition\GhlHandoff;
use App\Communications\Communications;
use App\Models\Communication;
use App\Models\WhatsAppLine;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\RealEstate\Instructions\Models\Instruction;
use Liberu\RealEstate\Parties\Models\Party;
use Liberu\RealEstate\Properties\Models\Property;
use Liberu\RealEstate\Viewings\Models\Viewing;
use RuntimeException;
use Throwable;

/**
 * WhatsApp conversations of the team's line with its contacts (owners of won GHL opportunities and clients the
 * team already manages). GoHighLevel keeps acquisition: unknown numbers are never turned into contacts here,
 * they wait in the resolution queue.
 *
 * Idempotency: an outgoing message is sent once per idempotency key (the send form's key, so a double click or a
 * retried request never sends twice); a webhook event is processed once per event id and a received message is
 * stored once per WhatsApp message id.
 */
final class WhatsApp
{
    /**
     * Extra template placeholders: name => fn (Party, ?Instruction): ?string. «Formularios y firma» registers
     * "enlace_firma" here (WhatsApp::placeholder('enlace_firma', fn ($party, $instruction) => …)).
     *
     * @var array<string, Closure(Party, ?Instruction): ?string>
     */
    private static array $placeholders = [];

    public static function placeholder(string $name, Closure $resolver): void
    {
        self::$placeholders[$name] = $resolver;
    }

    /**
     * WhatsApp to a contact through the shared communications log (App\Communications\Communications).
     *
     * @throws RuntimeException invalid input, line not connected or WAHA refusing the message (logged as «Not sent»)
     */
    public static function send(Party $party, string $phone, string $body, string $idempotencyKey, ?int $userId = null, ?Instruction $instruction = null, ?Waha $waha = null): Communication
    {
        $message = Communications::send($party->team_id, $party, 'whatsapp', $phone, $body,
            ['key' => $idempotencyKey, 'user_id' => $userId, 'instruction_id' => $instruction?->getKey(), 'related' => $instruction], $waha);
        if ($message->status === 'error') {
            throw new RuntimeException(__('WhatsApp did not accept the message: :m', ['m' => $message->error]));
        }

        return $message;
    }

    /**
     * A verified webhook event from WAHA. Returns what happened: procesado | duplicado | ignorado.
     *
     * @param  array<string, mixed>  $event
     */
    public static function handleEvent(array $event, ?string $requestId = null, ?Waha $waha = null): string
    {
        $eventId = (string) ($event['id'] ?? $requestId ?? '');
        $line = WhatsAppLine::query()->where('session', (string) ($event['session'] ?? ''))->first();
        if ($eventId === '' || $line === null) {
            return 'ignorado';
        }

        // Event row and its effects commit together: a failure lets WAHA retry; a success makes retries no-ops.
        return DB::transaction(function () use ($event, $eventId, $line, $waha): string {
            $new = DB::table('clarto_whatsapp_events')->insertOrIgnore([
                'event_id' => mb_substr($eventId, 0, 191), 'event' => mb_substr((string) ($event['event'] ?? ''), 0, 40), 'session' => $line->session, 'created_at' => now(),
            ]);
            if ($new === 0) {
                return 'duplicado';
            }

            $payload = (array) ($event['payload'] ?? []);

            return match ($event['event'] ?? null) {
                'session.status' => $line->applyStatus((string) ($payload['status'] ?? $line->status), $event['me'] ?? null) ? 'procesado' : 'ignorado',
                'message' => self::receive($line, $payload, $waha) ? 'procesado' : 'ignorado',
                'message.ack' => self::acknowledge($line, $payload) ? 'procesado' : 'ignorado',
                default => 'ignorado',
            };
        });
    }

    /** @param array<string, mixed> $payload */
    public static function receive(WhatsAppLine $line, array $payload, ?Waha $waha = null): ?Communication
    {
        $wahaId = self::messageId($payload);
        $from = (string) ($payload['from'] ?? '');
        if ($wahaId === null || ($payload['fromMe'] ?? false) || ! preg_match('/@(c\.us|s\.whatsapp\.net|lid)$/', $from)) {
            return null;    // own messages, groups, channels, broadcasts
        }

        $existing = Communication::query()->where('team_id', $line->team_id)->where('provider_id', $wahaId)->first();
        if ($existing !== null) {
            return $existing;
        }

        $phone = Phone::fromChatId($from)
            ?? Phone::fromChatId((string) data_get($payload, '_data.key.remoteJidAlt', data_get($payload, '_data.Info.SenderAlt', '')))
            ?? (str_ends_with($from, '@lid') ? ($waha ?? new Waha)->phoneForLid($line->session, $from) : null);
        if ($phone === null) {
            return null;
        }

        [$partyId, $candidates] = self::match($line->team_id, $phone);
        $body = trim((string) ($payload['body'] ?? ''));

        return Communication::query()->create([
            'team_id' => $line->team_id, 'channel' => 'whatsapp', 'direction' => Communication::IN, 'phone' => $phone, 'party_id' => $partyId,
            'body' => $body !== '' ? $body : (($payload['hasMedia'] ?? false) ? '['.__('Attachment').']' : '['.__('Message without text').']'),
            'status' => $partyId ? 'recibido' : Communication::UNLINKED,
            'candidates' => $partyId ? null : $candidates,
            'provider_id' => $wahaId, 'idempotency_key' => 'in:'.$line->team_id.':'.$wahaId,
            'sent_at' => isset($payload['timestamp']) ? now()->setTimestamp((int) $payload['timestamp']) : now(),
        ]);
    }

    /**
     * The contact a phone belongs to: the link the team confirmed (or created by sending), else the only contact
     * with that number. Several (or none) → null plus the candidates for the resolution queue.
     *
     * @return array{0: ?int, 1: list<int>}
     */
    public static function match(int|string $teamId, string $phone): array
    {
        $linked = DB::table('clarto_whatsapp_links')->where('team_id', $teamId)->where('phone', $phone)->value('party_id');
        if ($linked !== null && Party::query()->whereKey($linked)->exists()) {
            return [(int) $linked, []];
        }

        $candidates = self::partiesWithPhone($teamId, $phone)->modelKeys();

        return count($candidates) === 1 ? [(int) $candidates[0], []] : [null, array_map('intval', $candidates)];
    }

    /** @return Collection<int, Party> */
    public static function partiesWithPhone(int|string $teamId, string $phone): Collection
    {
        // ponytail: phones are stored free-form, so they are normalised in PHP over the team's contacts with a phone;
        // add a normalised, indexed column if contacts reach the tens of thousands.
        return Party::query()->forTeam($teamId)->whereNotNull('phone')->where('phone', '!=', '')
            ->get(['id', 'team_id', 'name', 'phone', 'type'])->filter(fn (Party $p): bool => Phone::e164($p->phone) === $phone)->values();
    }

    /** Resolution queue: this phone is this contact. Links the waiting messages and future ones. */
    public static function link(int|string $teamId, string $phone, int $partyId): int
    {
        abort_unless(Party::query()->forTeam($teamId)->whereKey($partyId)->exists(), 404);
        self::rememberLink($teamId, $phone, $partyId);

        return Communication::query()->where('team_id', $teamId)->where('phone', $phone)->where('status', Communication::UNLINKED)
            ->update(['party_id' => $partyId, 'status' => 'recibido', 'candidates' => null]);
    }

    /** Resolution queue: not a contact of the team (e.g. a lead still handled in GoHighLevel). */
    public static function dismiss(int|string $teamId, string $phone): int
    {
        return Communication::query()->where('team_id', $teamId)->where('phone', $phone)->where('status', Communication::UNLINKED)
            ->update(['status' => 'descartado']);
    }

    /** @return Collection<int, Communication> oldest first */
    public static function conversation(Party $party, int $limit = 100): Collection
    {
        return Communication::query()->where('team_id', $party->team_id)->where('party_id', $party->getKey())
            ->latest('id')->limit($limit)->get()->reverse()->values();
    }

    /** Fills a template from the record; unknown values stay as {placeholder} so the sender sees what is missing. */
    public static function render(string $body, Party $party, ?Instruction $instruction = null, ?string $agent = null): string
    {
        $instruction ??= Instruction::query()->where('team_id', $party->team_id)->where('party_id', $party->getKey())->whereNotNull('property_id')->latest('id')->first();
        $property = $instruction?->property_id ? Property::query()->find($instruction->property_id) : null;
        $viewing = Viewing::query()->where('team_id', $party->team_id)->where('starts_at', '>=', now())
            ->where(fn ($q) => $q->where('party_id', $party->getKey())->when($property, fn ($q) => $q->orWhere('property_id', $property->getKey())))
            ->orderBy('starts_at')->first();

        $values = [
            'nombre' => trim((string) Str::of($party->name)->before(' ')) ?: $party->name,
            'nombre_completo' => $party->name,
            'referencia' => $property?->reference(),
            'direccion' => $property?->address,
            'fecha_visita' => $viewing?->starts_at?->timezone('Europe/Madrid')->format('d/m/Y H:i'),
            'agente' => $agent,
            'empresa' => Team::query()->whereKey($party->team_id)->value('name') ?: config('app.name'),
        ];
        foreach (self::$placeholders as $name => $resolver) {
            $values[$name] = $resolver($party, $instruction);
        }

        return preg_replace_callback('/\{([a-z_]+)\}/', fn ($m) => filled($values[$m[1]] ?? null) ? (string) $values[$m[1]] : $m[0], $body) ?? $body;
    }

    /** Where the contact comes from (shown when sending): a won GHL opportunity or the team's own clients. */
    public static function origin(Party $party): string
    {
        return GhlHandoff::query()->where('party_id', $party->getKey())->where('status', 'procesado')->exists()
            ? __('Owner from an opportunity won in GoHighLevel')
            : __('Client managed by the team');
    }

    /** @param array<string, mixed> $payload */
    private static function acknowledge(WhatsAppLine $line, array $payload): bool
    {
        $status = [1 => 'enviado', 2 => 'entregado', 3 => 'leido', 4 => 'leido'][(int) ($payload['ack'] ?? 0)] ?? null;
        $order = ['pendiente' => 0, 'enviado' => 1, 'entregado' => 2, 'leido' => 3];
        $message = Communication::query()->where('team_id', $line->team_id)->where('provider_id', self::messageId($payload))->first();
        if ($status === null || $message === null || ($order[$message->status] ?? 9) >= $order[$status]) {
            return false;
        }

        return $message->update(['status' => $status]);
    }

    public static function rememberLink(int|string $teamId, string $phone, int $partyId): void
    {
        DB::table('clarto_whatsapp_links')->updateOrInsert(['team_id' => $teamId, 'phone' => $phone], ['party_id' => $partyId, 'updated_at' => now(), 'created_at' => now()]);
    }

    /** @param array<string, mixed> $message WAHA message (id is a string, or {_serialized} on some engines) */
    public static function messageId(array $message): ?string
    {
        $id = $message['id'] ?? null;
        $id = is_array($id) ? ($id['_serialized'] ?? $id['id'] ?? null) : $id;

        return filled($id) ? mb_substr((string) $id, 0, 191) : null;
    }
}
