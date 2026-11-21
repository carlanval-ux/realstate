<?php

declare(strict_types=1);

namespace App\SearchRequests;

use App\Collaborators\Collaborator;
use App\Collaborators\CollaboratorNotice;
use App\Collaborators\CollaboratorNotices;
use App\Communications\Communications;
use App\Models\Communication;
use App\Models\User;
use App\Modules\ClartoModules;
use App\Tarmio\TarmioTask;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\RealEstate\Properties\Domain\PropertyStatus;
use Liberu\RealEstate\Properties\Models\Property;
use Liberu\RealEstate\PropertiesFilament\Resources\PropertyResource;
use RuntimeException;

/**
 * «Solicitudes de inmuebles» operations (docs/clarto/solicitudes.md).
 *
 * Sending: one CollaboratorNotice per collaborator and send (contract of «Colaboradores»): it keeps the exact texts
 * (e-mail + ordered WhatsApp blocks) and the result per channel, never sends a channel or block twice, re-checks
 * the collaborator's access (SearchRequestAccess) right before each attempt and is retried every 10 minutes.
 *
 * Answers: from the panel (respond), or e-mail / WhatsApp replies (inbound) linked when the request is certain —
 * reply threading (quoted WhatsApp message, In-Reply-To / References) or its reference in the text, from a
 * collaborator it was sent to. Otherwise they wait in «Respuestas por vincular». Receipts never count as an answer.
 */
final class SearchRequests
{
    public const MODULE = 'solicitudes';

    public const KINDS = ['solicitud', 'actualizacion', 'aclaracion'];

    public const REFERENCE_PATTERN = '/\bSOL-\d{4}-\d{4,6}\b/i';

    /** Hook for the «Colaboradores» module: mark the property as contributed by the collaborator (set at boot). */
    public static ?Closure $propertyLinked = null;

    /**
     * Adds recipients (not sent yet). Withdrawn recipients chosen again come back.
     *
     * @param  list<int|string>  $collaboratorIds
     */
    public static function addRecipients(SearchRequest $request, array $collaboratorIds, ?Authenticatable $user = null): int
    {
        $added = [];
        foreach (Collaborator::query()->where('team_id', $request->team_id)->whereKey($collaboratorIds)->active()->get() as $collaborator) {
            $recipient = $request->recipients()->firstOrNew(['collaborator_id' => $collaborator->getKey()]);
            if (! $recipient->exists || $recipient->status === 'retirada') {
                $recipient->fill(['status' => 'pendiente', 'added_by' => $user?->getAuthIdentifier()])->save();
                $added[] = $collaborator->name;
            }
        }
        if ($added !== []) {
            $request->log('destinatarios', ['colaboradores' => $added], $user?->getAuthIdentifier());
        }

        return count($added);
    }

    /**
     * The texts a collaborator would receive (preview = what is sent).
     *
     * @return array{subject: string, email: string, whatsapp: list<string>}
     */
    public static function compose(SearchRequest $request, Collaborator $collaborator, string $kind, ?string $note = null): array
    {
        return SearchRequestMessages::compose($request, $kind, $collaborator->firstName(), self::locale($collaborator), self::link($request), $note);
    }

    /**
     * Sends the request (kind "solicitud": recipients not sent yet), an update (every recipient that received it; the
     * version goes up) or a clarification (one recipient, with the question). Returns the names it went to; each
     * message's result is in its notice.
     *
     * @param  list<int>|null  $recipientIds  null: every recipient the kind applies to
     * @return list<string>
     *
     * @throws RuntimeException module off, wrong status, nobody to send to
     */
    public static function send(SearchRequest $request, string $kind, ?array $recipientIds, ?Authenticatable $user, ?string $note = null): array
    {
        self::ensureEnabled();
        throw_unless(in_array($kind, self::KINDS, true), new RuntimeException('Unknown kind.'));
        $allowed = $kind === 'solicitud' ? ['borrador', ...SearchRequest::ACTIVE] : [...SearchRequest::ACTIVE, 'cubierta'];
        throw_unless(in_array($request->status, $allowed, true), new RuntimeException(__('solicitudes.aviso.not_active')));
        throw_if($kind !== 'solicitud' && blank($note), new RuntimeException(__('solicitudes.campo.'.($kind === 'aclaracion' ? 'question' : 'changes'))));

        $batch = (string) Str::uuid();
        $userId = $user?->getAuthIdentifier();

        // Status first (committed), so the access check of each notice sees the request open and the recipient «enviada».
        [$recipients, $version] = DB::transaction(function () use ($request, $kind, $recipientIds, $userId): array {
            $request->refresh();
            $request->forceFill([
                'status' => $request->status === 'borrador' ? 'abierta' : $request->status,
                'version' => $kind === 'actualizacion' ? $request->version + 1 : $request->version,
                'first_sent_at' => $request->first_sent_at ?? now(), 'updated_by' => $userId,
            ])->saveQuietly();
            $recipients = $request->recipients()->with('collaborator')
                ->when($recipientIds !== null, fn ($q) => $q->whereKey($recipientIds))
                ->when($kind === 'solicitud', fn ($q) => $q->where('status', 'pendiente'), fn ($q) => $q->whereIn('status', ['enviada', 'respondida']))
                ->get()->filter(fn (SearchRequestRecipient $r) => $r->collaborator?->isEnabled());
            throw_if($recipients->isEmpty(), new RuntimeException(__('solicitudes.aviso.nothing_to_send')));
            foreach ($recipients as $recipient) {
                $recipient->fill(['sent_version' => $request->version, 'sent_at' => now()] + ($recipient->status === 'pendiente' ? ['status' => 'enviada'] : []))->save();
            }

            return [$recipients, $request->version];
        });

        foreach ($recipients as $recipient) {
            $c = $recipient->collaborator;
            $m = self::compose($request, $c, $kind, $note);
            $key = "solicitud:{$request->getKey()}:{$kind}:v{$version}:{$batch}:colaborador:{$c->getKey()}";
            // On the worker (or right away with the "sync" queue): sending to every collaborator must not block the page.
            dispatch(fn () => CollaboratorNotices::send($c, $request, $key, $m['subject'], $m['email'], $m['whatsapp'], $userId));
        }
        $names = $recipients->map(fn (SearchRequestRecipient $r) => $r->collaborator->name)->values()->all();
        $request->log($kind === 'solicitud' ? 'enviada' : $kind, array_filter(['version' => $version, 'colaboradores' => $names, 'nota' => $note]), $userId);
        self::followUpTask($request);

        return $names;
    }

    /** Sends again what failed of a message (only the failed channel or blocks; access is checked first). */
    public static function retry(CollaboratorNotice $notice, ?Authenticatable $user = null): CollaboratorNotice
    {
        self::ensureEnabled();
        $request = $notice->subject;
        throw_unless($request instanceof SearchRequest, new RuntimeException('Not a request notice.'));
        $request->log('reintento', ['colaborador' => $notice->collaborator?->name], $user?->getAuthIdentifier());

        return CollaboratorNotices::dispatch($notice);
    }

    /**
     * An answer of the collaborator. From the panel the request must still accept answers.
     *
     * @param  array<string, mixed>  $data  kind, description, location, price, features, links, photos, estimated_time
     *
     * @throws RuntimeException the request no longer accepts answers (panel)
     */
    public static function respond(SearchRequestRecipient $recipient, array $data, ?Authenticatable $user, string $channel = 'panel', ?Communication $communication = null): SearchRequestResponse
    {
        $request = $recipient->request;
        throw_if($channel === 'panel' && (! $request->isActive() || ! $recipient->wasSent()), new RuntimeException(__('solicitudes.aviso.not_active')));
        throw_if(filled($data['kind'] ?? null) && ! in_array($data['kind'], SearchRequestResponse::KINDS, true), new RuntimeException('Unknown answer.'));

        $response = DB::transaction(function () use ($recipient, $request, $data, $user, $channel, $communication): SearchRequestResponse {
            $response = $request->responses()->create([
                'team_id' => $request->team_id, 'recipient_id' => $recipient->getKey(), 'collaborator_id' => $recipient->collaborator_id, 'channel' => $channel,
                'kind' => filled($data['kind'] ?? null) ? $data['kind'] : null,
                'description' => $data['description'] ?? null, 'location' => $data['location'] ?? null,
                'price' => is_numeric($data['price'] ?? null) ? (int) $data['price'] : null, 'features' => $data['features'] ?? null,
                'links' => array_values(array_filter(array_map(fn ($l) => is_array($l) ? ($l['url'] ?? null) : $l, (array) ($data['links'] ?? [])))) ?: null,
                'photos' => $data['photos'] ?? null, 'estimated_time' => $data['estimated_time'] ?? null,
                'communication_id' => $communication?->getKey(), 'user_id' => $user?->getAuthIdentifier(),
            ]);
            $recipient->fill(['status' => 'respondida', 'responded_at' => now()] + ($response->kind ? ['last_response' => $response->kind] : []))->save();
            if ($request->status === 'abierta' && in_array($response->kind, SearchRequestResponse::PROPOSALS, true)) {
                $request->forceFill(['status' => 'en_evaluacion'])->saveQuietly();
                $request->log('estado', ['estado' => SearchRequest::label('estado', 'en_evaluacion')], $user?->getAuthIdentifier());
            }
            $request->log('respuesta', ['colaborador' => $recipient->collaborator?->name, 'respuesta' => $response->kindLabel(), 'canal' => __('solicitudes.canal.'.$channel)], $user?->getAuthIdentifier());

            return $response;
        });

        rescue(fn () => TarmioTask::sync($request, 'revisar_propuestas', 'Revisar respuestas de colaboradores · '.$request->reference.' · '.$request->title,
            self::assignee($request), now()->addWeekday(), self::staffUrl($request)), report: true);

        return $response;
    }

    /** Withdraws a recipient: access ends and its pending messages are cancelled before going out. */
    public static function withdraw(SearchRequestRecipient $recipient, ?Authenticatable $user): void
    {
        $recipient->update(['status' => 'retirada']);
        CollaboratorNotices::cancelOpen($recipient->collaborator, (new SearchRequest)->getMorphClass(), [$recipient->search_request_id], 'retirado');
        $recipient->request->log('retirado', ['colaborador' => $recipient->collaborator?->name], $user?->getAuthIdentifier());
    }

    /** General status and outcome (Cubierta / Cerrada record who closed it and when). */
    public static function changeStatus(SearchRequest $request, string $status, ?string $outcome, ?Authenticatable $user): void
    {
        throw_unless(in_array($status, SearchRequest::STATUSES, true), new RuntimeException('Unknown status.'));
        $closing = in_array($status, ['cubierta', 'cerrada'], true);
        $request->forceFill([
            'status' => $status, 'outcome' => filled($outcome) ? $outcome : $request->outcome, 'updated_by' => $user?->getAuthIdentifier(),
            'closed_at' => $closing ? ($request->closed_at ?? now()) : null, 'closed_by' => $closing ? $user?->getAuthIdentifier() : null,
        ])->saveQuietly();
        $request->log('estado', array_filter(['estado' => SearchRequest::label('estado', $status), 'resultado' => $outcome]), $user?->getAuthIdentifier());
    }

    /** The team's review of a proposal: under study / discarded, with notes. */
    public static function review(SearchRequestResponse $response, string $status, ?string $notes, ?string $kind, Authenticatable $user): void
    {
        throw_unless(in_array($status, SearchRequestResponse::STATUSES, true), new RuntimeException('Unknown status.'));
        $response->update(array_filter(['status' => $status, 'team_notes' => $notes, 'kind' => $kind], fn ($v) => $v !== null));
        $response->request->log('propuesta', ['colaborador' => $response->collaborator?->name, 'estado' => SearchRequest::label('propuesta', $status)], $user->getAuthIdentifier());
    }

    /**
     * The team chose a proposal: a draft property is created from it (or an existing one of the team is linked), the
     * request is marked «Cubierta» and its management continues in the property record (returned URL).
     */
    public static function selectProposal(SearchRequestResponse $response, ?Property $existing, Authenticatable $user): string
    {
        $request = $response->request;
        throw_if($existing !== null && (string) $existing->team_id !== (string) $request->team_id, new RuntimeException('Property of another team.'));

        $property = DB::transaction(function () use ($response, $request, $existing, $user): Property {
            $collaborator = $response->collaborator;
            $note = "Propuesta de {$collaborator?->name} (colaborador) para la solicitud {$request->reference} · {$request->title}."
                .(filled($response->description) ? "\n".$response->description : '')
                .(filled($response->links) ? "\nEnlaces: ".implode(' ', (array) $response->links) : '');
            $property = $existing ?? new Property;
            if ($existing === null) {
                $property->forceFill([
                    'team_id' => $request->team_id, 'created_by' => $user->getAuthIdentifier(), 'status' => PropertyStatus::Draft,
                    'deal_type' => in_array($request->operation, ['alquiler', 'alquiler_temporada'], true) ? 'rent' : 'sale',
                    'property_type' => ['piso' => 'apartment', 'atico' => 'apartment', 'casa' => 'house', 'chalet' => 'villa', 'local' => 'commercial', 'oficina' => 'commercial', 'nave' => 'commercial', 'terreno' => 'land'][$request->property_type] ?? 'residential',
                    'title' => Str::limit($request->title, 150),
                    'address' => $response->location ?: ($request->address ?: (trim(($request->zones ? $request->zones.', ' : '').$request->municipality) ?: 'Pendiente de completar')),
                    'price' => $response->price, 'area_sqft' => $request->surface_min, 'bedrooms' => $request->bedrooms_min,
                    'country' => 'ES', 'currency' => 'EUR',
                ]);
            }
            $property->forceFill(['internal_notes' => trim(((string) $property->internal_notes)."\n".$note)])->save();

            $response->update(['status' => 'seleccionada', 'property_id' => $property->getKey()]);
            $request->forceFill(['property_id' => $property->getKey(), 'updated_by' => $user->getAuthIdentifier()])->saveQuietly();
            $request->log('inmueble', ['inmueble' => $property->reference(), 'colaborador' => $collaborator?->name, 'nuevo' => $existing === null ? 'sí' : 'no'], $user->getAuthIdentifier());
            if ($request->status !== 'cubierta') {
                self::changeStatus($request, 'cubierta', null, $user);
            }

            return $property;
        });
        if (self::$propertyLinked !== null) {
            rescue(fn () => (self::$propertyLinked)($property, $response), report: true);
        }

        return PropertyResource::getUrl('edit', ['record' => $property], panel: 'admin', tenant: Team::query()->find($request->team_id));
    }

    /**
     * Inbound WhatsApp (Communication::created) and e-mail (inboundMail) replies.
     *
     * @param  array{in_reply_to?: list<string>, auto?: bool}  $meta
     * @return string vinculada | pendiente | acuse | ignorada
     */
    public static function inbound(Communication $c, array $meta = []): string
    {
        if ($c->direction !== Communication::IN || ! in_array($c->channel, ['email', 'whatsapp'], true)
            || SearchRequestInboxItem::query()->where('communication_id', $c->getKey())->exists()
            || SearchRequestResponse::query()->where('communication_id', $c->getKey())->exists()) {
            return 'ignorada';
        }
        $collaborator = Collaborator::findByAddress($c->team_id, $c->channel === 'whatsapp' ? $c->phone : null, $c->channel === 'email' ? $c->email : null);
        $recipients = $collaborator === null ? collect() : SearchRequestRecipient::query()->with('request', 'collaborator')
            ->where('collaborator_id', $collaborator->getKey())->whereIn('status', ['enviada', 'respondida'])
            ->whereHas('request', fn ($q) => $q->where('team_id', $c->team_id))->get();

        $recipient = self::certainRecipient($c, $recipients, (array) ($meta['in_reply_to'] ?? []));
        if ($recipient !== null) {
            self::markHandled($c, $recipient);
            if ($meta['auto'] ?? false) {   // read / delivery receipt or automatic reply: never an answer
                $recipient->request->log('acuse', ['colaborador' => $recipient->collaborator?->name, 'canal' => $c->channel], null);

                return 'acuse';
            }
            self::respond($recipient, ['description' => self::text($c)], null, $c->channel, $c);

            return 'vinculada';
        }
        if ($meta['auto'] ?? false) {
            return 'ignorada';
        }

        // Not certain: queued when it comes from a collaborator with requests, or quotes one of the team's references.
        $refs = self::references($c);
        $byReference = $refs->isEmpty() ? collect() : SearchRequest::query()->forTeam($c->team_id)->whereIn('reference', $refs)->pluck('id');
        $candidates = $recipients->pluck('search_request_id')->merge($byReference)->unique()->values()->all();
        if ($candidates === []) {
            return 'ignorada';
        }
        SearchRequestInboxItem::query()->firstOrCreate(['communication_id' => $c->getKey()], [
            'team_id' => $c->team_id, 'collaborator_id' => $collaborator?->getKey(), 'candidates' => $candidates,
            'reason' => $collaborator === null ? 'remitente_desconocido' : (count($candidates) > 1 ? 'varias_solicitudes' : 'sin_referencia'),
        ]);
        self::markHandled($c, null);

        return 'pendiente';
    }

    /**
     * Inbound e-mail posted by the replies mailbox forwarder. Idempotent per Message-ID.
     *
     * @param  array{from: string, subject?: ?string, text?: ?string, message_id?: ?string, in_reply_to?: ?string, references?: ?string, auto_submitted?: ?string, content_type?: ?string}  $mail
     * @return string vinculada | pendiente | acuse | ignorada | duplicada
     */
    public static function inboundMail(array $mail): string
    {
        $from = Str::lower(trim((string) (preg_match('/<([^>]+)>/', (string) ($mail['from'] ?? ''), $m) ? $m[1] : ($mail['from'] ?? ''))));
        $threadIds = self::messageIds(($mail['in_reply_to'] ?? '').' '.($mail['references'] ?? ''));
        $subject = trim((string) ($mail['subject'] ?? ''));
        $text = trim((string) ($mail['text'] ?? ''));
        if (filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            return 'ignorada';
        }

        // Team: the thread's, else the reference's, else the only team where this address is a collaborator.
        $teamId = Communication::query()->where('channel', 'email')->where('direction', Communication::OUT)->whereKey(self::threadCommunicationIds($threadIds) ?: [0])->value('team_id');
        if ($teamId === null && preg_match(self::REFERENCE_PATTERN, $subject.' '.$text, $r)) {
            $teams = SearchRequest::query()->where('reference', Str::upper($r[0]))->pluck('team_id')->unique();
            $teamId = $teams->count() === 1 ? $teams->first() : null;
        }
        if ($teamId === null) {
            $teams = Collaborator::query()->where('email', $from)->where('status', '!=', Collaborator::INACTIVE)->pluck('team_id')->unique();
            $teamId = $teams->count() === 1 ? $teams->first() : null;
        }
        if ($teamId === null) {
            return 'ignorada';
        }

        $key = 'in-mail:'.$teamId.':'.sha1((string) ($mail['message_id'] ?? $from.$subject.$text));
        if (Communication::query()->where('idempotency_key', $key)->exists()) {
            return 'duplicada';
        }
        $c = Communications::record($teamId, 'email', Communication::IN, [
            'email' => $from, 'subject' => Str::limit($subject, 240) ?: null, 'body' => $text !== '' ? Str::limit($text, 20000) : '['.__('Message without text').']',
            'status' => 'recibido', 'source' => 'solicitudes', 'idempotency_key' => $key,
            'provider_id' => filled($mail['message_id'] ?? null) ? Str::limit((string) $mail['message_id'], 180, '') : null,
        ]);

        return $c === null ? 'ignorada' : self::inbound($c, ['in_reply_to' => $threadIds, 'auto' => self::isAutomatic($mail)]);
    }

    /** Links a queued reply to a request (and to the collaborator it was sent to). */
    public static function linkInboxItem(SearchRequestInboxItem $item, SearchRequest $request, ?int $collaboratorId, Authenticatable $user): SearchRequestResponse
    {
        throw_if((string) $request->team_id !== (string) $item->team_id || $item->status !== 'pendiente', new RuntimeException('Not linkable.'));
        $recipient = $request->recipients()->where('collaborator_id', $collaboratorId ?? $item->collaborator_id ?? 0)->firstOrFail();
        $c = $item->communication;
        $response = self::respond($recipient, ['description' => self::text($c)], $user, $c->channel, $c);
        $item->update(['status' => 'vinculado', 'response_id' => $response->getKey(), 'resolved_by' => $user->getAuthIdentifier(), 'resolved_at' => now()]);
        self::markHandled($c, $recipient);
        $request->log('vinculada', ['colaborador' => $recipient->collaborator?->name], $user->getAuthIdentifier());

        return $response;
    }

    public static function dismissInboxItem(SearchRequestInboxItem $item, Authenticatable $user): void
    {
        $item->update(['status' => 'descartado', 'resolved_by' => $user->getAuthIdentifier(), 'resolved_at' => now()]);
    }

    /**
     * MessageSending: e-mails to collaborators get a Message-ID derived from their communication
     * (<clarto-com-{id}@domain>), so a reply (In-Reply-To / References) points back to it with certainty and
     * nothing has to be stored.
     */
    public static function stampMessageId(MessageSending $event): void
    {
        $headers = $event->message->getHeaders();
        $header = $headers->get(Communications::MAIL_HEADER);
        if ($header === null || $headers->has('Message-ID')) {
            return;
        }
        $id = (int) $header->getBodyAsString();
        if (Communication::query()->whereKey($id)->where('related_type', (new CollaboratorNotice)->getMorphClass())->exists()) {
            $headers->addIdHeader('Message-ID', self::messageIdFor($id));
        }
    }

    public static function messageIdFor(int $communicationId): string
    {
        $domain = Str::after((string) config('mail.from.address'), '@') ?: (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'clarto.local');

        return 'clarto-com-'.$communicationId.'@'.$domain;
    }

    /** @return list<int> communication ids our Message-IDs in In-Reply-To / References point to */
    private static function threadCommunicationIds(array $threadIds): array
    {
        return array_values(array_unique(array_map('intval', array_filter(array_map(fn (string $id) => preg_match('/^<clarto-com-(\d+)@/', $id, $m) ? $m[1] : null, $threadIds)))));
    }

    /** Link in every message: the request in the collaborator panel (login there, access checked on the server). */
    public static function link(SearchRequest $request): string
    {
        // = Colaboradores\Resources\SearchRequestResource view URL; built by hand so it never depends on the panel being booted (queue, console).
        return url(\App\Collaborators\CollaboratorAccess::PANEL.'/solicitudes/'.$request->getKey());
    }

    public static function staffUrl(SearchRequest $request): string
    {
        return \App\Filament\Resources\SearchRequestResource::getUrl('view', ['record' => $request], panel: 'admin', tenant: Team::query()->find($request->team_id));
    }

    public static function locale(Collaborator $collaborator): string
    {
        return in_array($collaborator->locale, ['es', 'en', 'tr'], true) ? $collaborator->locale : 'es';
    }

    public static function enabled(): bool
    {
        return self::refusal() === null;
    }

    public static function refusal(): ?string
    {
        return ClartoModules::refusal(self::MODULE)
            ?? (in_array('colaboradores', ClartoModules::keys(), true) ? ClartoModules::refusal('colaboradores') : null);
    }

    private static function ensureEnabled(): void
    {
        if (($refusal = self::refusal()) !== null) {
            throw new \App\Modules\ModuleDisabled(self::MODULE, $refusal);
        }
    }

    /** @param Collection<int, SearchRequestRecipient> $recipients */
    private static function certainRecipient(Communication $c, Collection $recipients, array $threadIds): ?SearchRequestRecipient
    {
        if ($recipients->isEmpty()) {
            return null;
        }
        // 1. Reply threading: the WhatsApp message it quotes, or the e-mail it answers, is one of our notices to this collaborator.
        $noticeIds = CollaboratorNotice::query()->where('collaborator_id', $recipients->first()->collaborator_id)
            ->where('subject_type', (new SearchRequest)->getMorphClass())->whereIn('subject_id', $recipients->pluck('search_request_id'))->pluck('subject_id', 'id');
        $ours = Communication::query()->where('team_id', $c->team_id)->where('direction', Communication::OUT)->where('channel', $c->channel)
            ->where('related_type', (new CollaboratorNotice)->getMorphClass())->whereIn('related_id', $noticeIds->keys()->map(fn ($id) => (string) $id));
        $quoted = null;
        if ($c->channel === 'whatsapp' && filled($c->reply_to)) {
            // WAHA ids come serialised ("true_34600…@c.us_3EB0…") or bare ("3EB0…") depending on the engine: compare the last part.
            $bare = fn (?string $id): string => Str::afterLast((string) $id, '_');
            $quoted = $ours->whereNotNull('provider_id')->get(['related_id', 'provider_id'])->first(fn (Communication $o) => $bare($o->provider_id) === $bare($c->reply_to));
        } elseif ($c->channel === 'email' && ($ids = self::threadCommunicationIds($threadIds)) !== []) {
            $quoted = $ours->whereKey($ids)->first(['related_id']);
        }
        if ($quoted !== null) {
            return $recipients->firstWhere('search_request_id', $noticeIds[(int) $quoted->related_id] ?? 0);
        }
        // 2. The reference of exactly one request sent to this collaborator appears in the text.
        $refs = self::references($c);
        $matching = $recipients->filter(fn (SearchRequestRecipient $r) => $refs->contains($r->request->reference))->unique('search_request_id');

        return $matching->count() === 1 ? $matching->first() : null;
    }

    /** @return Collection<int, string> */
    private static function references(Communication $c): Collection
    {
        preg_match_all(self::REFERENCE_PATTERN, $c->subject.' '.$c->body, $m);

        return collect($m[0])->map(fn (string $r) => Str::upper($r))->unique()->values();
    }

    /** It leaves the WhatsApp «to link» queue: this module handles it (linked, or in its own queue). */
    private static function markHandled(Communication $c, ?SearchRequestRecipient $recipient): void
    {
        $c->forceFill(array_filter([
            'status' => $c->status === Communication::UNLINKED ? 'recibido' : null,
            'related_type' => $recipient?->getMorphClass(), 'related_id' => $recipient ? (string) $recipient->getKey() : null,
        ], fn ($v) => $v !== null))->save();
    }

    private static function text(Communication $c): string
    {
        return trim(($c->channel === 'email' && filled($c->subject) ? $c->subject."\n\n" : '').$c->body);
    }

    /** @return list<string> "<id@host>" values of In-Reply-To / References */
    private static function messageIds(string $headers): array
    {
        preg_match_all('/<[^<>\s]+>/', $headers, $m);

        return array_values(array_unique($m[0]));
    }

    /** Read/delivery receipts (DSN, MDN), auto-replies and out-of-office: never an answer. */
    private static function isAutomatic(array $mail): bool
    {
        $auto = Str::lower(trim((string) ($mail['auto_submitted'] ?? '')));
        $type = Str::lower((string) ($mail['content_type'] ?? ''));

        return ($auto !== '' && $auto !== 'no')
            || str_contains($type, 'multipart/report') || str_contains($type, 'disposition-notification') || str_contains($type, 'delivery-status')
            || (bool) preg_match('/^\s*(le[ií]do|read|entregado|delivered|undeliverable|no entregado|respuesta autom[aá]tica|automatic reply|auto(matic)?[- ]?reply|out of office|fuera de la oficina|ausente)\b/iu', (string) ($mail['subject'] ?? ''));
    }

    private static function assignee(SearchRequest $request): ?string
    {
        return $request->owner()->value('email') ?: (config('solicitudes.responsable') ?: User::query()->whereKey(Team::query()->whereKey($request->team_id)->value('user_id'))->value('email'));
    }

    /** TARMIO: follow-up of the request until its deadline (same task updated on every send). */
    private static function followUpTask(SearchRequest $request): void
    {
        rescue(fn () => TarmioTask::sync($request, 'seguimiento_solicitud', 'Seguimiento de la solicitud a colaboradores · '.$request->reference.' · '.$request->title,
            self::assignee($request), $request->respond_by ?? now()->addWeekdays(5), self::staffUrl($request)), report: true);
    }
}
