<?php

declare(strict_types=1);

namespace App\InboundMail;

use App\Ai\AiQuotaExceeded;
use App\Collaborators\Collaborator;
use App\Collaborators\CollaboratorNotice;
use App\Communications\Communications;
use App\Filament\Pages\InboundMailInbox;
use App\Models\AiSetting;
use App\Models\Communication;
use App\Models\User;
use App\Modules\ClartoModules;
use App\Owners\OwnerProfile;
use App\SearchRequests\SearchRequest;
use App\SearchRequests\SearchRequestRecipient;
use App\SearchRequests\SearchRequests;
use App\Tarmio\TarmioTask;
use App\Valuations\ValuationRequest;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\RealEstate\Parties\Models\Party;
use RuntimeException;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Throwable;

/**
 * «Correo entrante» (docs/clarto/correo-entrante.md): reads the company mailbox (read-only), keeps a copy of each
 * message, classifies it — deterministic signals first (reply threading, SOL-/VAL- references, staff rules, known
 * sender), AI only for the rest with a redacted excerpt — and routes it:
 *  - reply to a property request → «Solicitudes» (same service as the HTTP forwarder);
 *  - owner / client with a record → their communications timeline;
 *  - new enquiry, supplier, official notice → «Entrada» by area with a TARMIO task to the area owner;
 *  - advertising and automatic messages (receipts, bounces, out-of-office) → archived in the panel only;
 *  - low confidence or conflicting signals → «Pendiente de revisar» with the suggestion; corrections become rules.
 * Never replies, never forwards, never touches the mailbox.
 */
final class InboundMails
{
    public const MODULE = 'correo';

    /** Below this AI confidence, staff decide. */
    public const MIN_CONFIDENCE = 0.7;

    public const MAX_ATTACHMENT_BYTES = 20 * 1024 * 1024;

    /** Signal strength order: the first level with a signal decides (two categories at that level = conflict). */
    private const LEVELS = ['hilo' => 1.0, 'referencia' => 0.95, 'regla' => 1.0, 'remitente' => 0.9, 'cabeceras' => 0.8];

    private const VALUATION_PATTERN = '/\bVAL-\d{6,}\b/i';

    /** Set after a rate limit of the (free) AI: no calls until it expires. */
    public const AI_PAUSE = 'clarto:correo-ia-pausa';

    /** Messages that waited for the AI are retried for this long, a few per poll. */
    private const AI_RETRY_DAYS = 3;

    // ── Polling ─────────────────────────────────────────────────────────────────────────────────────────────────

    public static function refusal(): ?string
    {
        return ClartoModules::refusal(self::MODULE);
    }

    public static function mailboxConfigured(): bool
    {
        return filled(config('correo-entrante.username')) && filled(config('correo-entrante.password'));
    }

    public static function connect(): ImapMailbox
    {
        $mailbox = ImapMailbox::connect((string) config('correo-entrante.host'), (int) config('correo-entrante.port'));
        $mailbox->login((string) config('correo-entrante.username'), (string) config('correo-entrante.password'));

        return $mailbox;
    }

    /**
     * One poll of the mailbox for the company that has polling on (when its interval has passed, or $force).
     * First run: starts from the newest message (history is not imported). UIDVALIDITY change: re-reads since the
     * last poll (idempotent by Message-ID).
     *
     * @return array{estado: string, nuevos?: int, error?: string}
     */
    public static function poll(?ImapMailbox $mailbox = null, bool $force = false): array
    {
        if (self::refusal() !== null) {
            return ['estado' => 'modulo_desactivado'];
        }
        $teams = InboundMailSettings::query()->where('polling', true)->get();
        if ($teams->count() !== 1) {
            return ['estado' => $teams->isEmpty() ? 'sin_sondeo' : 'varias_empresas'];
        }
        $s = $teams->first();
        self::purge($s);
        if (! $force && $s->last_poll_at !== null && $s->last_poll_at->gt(now()->subMinutes(max(1, (int) $s->interval_minutes))->addSeconds(20))) {
            return ['estado' => 'esperando'];
        }
        if ($mailbox === null && ! self::mailboxConfigured()) {
            $s->forceFill(['last_poll_at' => now(), 'last_error' => __('correo.modulo.falta_buzon')])->save();

            return ['estado' => 'sin_buzon'];
        }

        return Cache::lock('clarto:correo-sondeo', 900)->get(function () use ($mailbox, $s): array {
            $count = 0;
            try {
                $mailbox ??= self::connect();
                $info = $mailbox->examine((string) config('correo-entrante.folder', 'INBOX'));
                if ($s->uidvalidity === null) {
                    $s->forceFill(['uidvalidity' => $info['uidvalidity'], 'last_uid' => $info['uidnext'] > 0 ? $info['uidnext'] - 1 : (int) max([0, ...$mailbox->uidsFrom(1)])])->save();
                    $uids = [];
                } elseif ((int) $s->uidvalidity !== $info['uidvalidity']) {
                    $uids = $mailbox->uidsSince(($s->last_poll_at ?? now())->copy()->subDay());
                    $s->forceFill(['uidvalidity' => $info['uidvalidity'], 'last_uid' => 0])->save();
                } else {
                    $uids = $mailbox->uidsFrom((int) $s->last_uid + 1);
                }
                foreach (array_slice($uids, 0, max(1, (int) config('correo-entrante.batch', 50))) as $uid) {
                    $raw = $mailbox->fetch($uid);
                    if ($raw !== null) {
                        $count += self::ingest($s->team_id, $raw, (int) $info['uidvalidity'], $uid)->wasRecentlyCreated ? 1 : 0;
                    }
                    $s->forceFill(['last_uid' => max((int) $s->last_uid, $uid)])->save();   // resume after a crash
                }
                self::retryPending($s->team_id);
                $s->forceFill(['last_error' => null, 'last_count' => $count]);
            } catch (Throwable $e) {
                report($e);
                $s->forceFill(['last_error' => Str::limit($e->getMessage(), 290)]);
            } finally {
                $mailbox?->logout();
                $s->forceFill(['last_poll_at' => now()])->save();
            }

            return $s->last_error === null ? ['estado' => 'ok', 'nuevos' => $count] : ['estado' => 'error', 'error' => $s->last_error];
        }) ?: ['estado' => 'en_curso'];
    }

    /** Stores one message (idempotent by Message-ID) and triages it. */
    public static function ingest(int|string $teamId, string $raw, ?int $uidvalidity = null, ?int $uid = null): InboundMail
    {
        $msg = MimeMessage::parse($raw);
        $messageId = self::firstId((string) $msg->header('Message-ID'));
        $hash = sha1($messageId !== null ? strtolower($messageId) : $raw);
        if ($existing = InboundMail::query()->where('team_id', $teamId)->where('message_hash', $hash)->first()) {
            return $existing;
        }
        [$email, $name] = $msg->from();
        $date = rescue(fn () => filled($msg->header('Date')) ? \Illuminate\Support\Carbon::parse((string) $msg->header('Date')) : null, null, report: false);

        try {
            $mail = InboundMail::query()->create([
                'team_id' => $teamId, 'message_hash' => $hash, 'message_id' => $messageId !== null ? Str::limit($messageId, 495, '') : null,
                'uidvalidity' => $uidvalidity, 'uid' => $uid, 'from_email' => $email !== null ? Str::limit($email, 320, '') : null,
                'from_name' => $name !== null ? Str::limit($name, 195) : null, 'to' => Str::limit((string) $msg->header('To'), 995),
                'subject' => Str::limit(trim((string) $msg->header('Subject')), 995), 'sent_at' => $date,
                'in_reply_to' => $msg->header('In-Reply-To'), 'references' => $msg->header('References'),
                'is_auto' => self::isAutomatic($msg, $email), 'text_body' => Str::limit(trim($msg->text), 200000),
                'html_body' => $msg->html !== '' ? self::sanitize($msg->html) : null, 'status' => 'revisar',
            ]);
        } catch (UniqueConstraintViolationException) {
            return InboundMail::query()->where('team_id', $teamId)->where('message_hash', $hash)->firstOrFail();
        }

        $files = [];
        foreach ($msg->attachments as $i => $a) {
            $size = strlen($a['content']);
            $path = $size <= self::MAX_ATTACHMENT_BYTES ? 'clarto/correo/'.$teamId.'/'.$mail->getKey().'/'.($i + 1).'-'.$a['name'] : null;
            if ($path !== null) {
                Storage::disk(InboundMail::DISK)->put($path, $a['content']);
            }
            $files[] = ['name' => $a['name'], 'type' => $a['type'], 'size' => $size, 'path' => $path];
        }
        $mail->forceFill(['attachments' => $files ?: null])->save();

        try {
            self::triage($mail, $msg);
        } catch (Throwable $e) {
            report($e);
            $mail->forceFill(['status' => 'revisar', 'reason' => 'error'])->save();
        }

        return $mail;
    }

    /**
     * Messages left in review only because the AI was unavailable or rate-limited: classified again once it answers
     * (a few per poll, oldest first). Staff can still classify them by hand meanwhile.
     */
    public static function retryPending(int|string $teamId, int $limit = 10): int
    {
        $n = 0;
        foreach (InboundMail::query()->where('team_id', $teamId)->where('status', 'revisar')->where('reason', 'ia_no_disponible')
            ->where('created_at', '>=', now()->subDays(self::AI_RETRY_DAYS))->oldest('id')->limit($limit)->get() as $mail) {
            if (Cache::has(self::AI_PAUSE)) {
                break;
            }
            try {
                self::triage($mail);
                $n += $mail->reason === 'ia_no_disponible' ? 0 : 1;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $n;
    }

    // ── Classification ──────────────────────────────────────────────────────────────────────────────────────────

    /** Classifies and routes a stored message. */
    public static function triage(InboundMail $mail, ?MimeMessage $msg = null): void
    {
        $d = self::classify($mail, $msg);
        if ($mail->is_auto) {
            if ($d['category'] === 'solicitud') {
                self::toRequests($mail);   // logged there as a receipt, never as an answer
            }
            $mail->forceFill(['category' => 'automatico', 'method' => 'cabeceras', 'confidence' => 1, 'status' => 'archivado', 'route' => 'archivo'])->save();

            return;
        }
        if ($d['category'] === null) {
            $mail->forceFill(['status' => 'revisar', 'suggested_category' => $d['suggested'] ?? null, 'suggested_area' => $d['area'] ?? null,
                'reason' => $d['reason'], 'confidence' => $d['confidence'] ?? null, 'method' => $d['method'] ?? null, 'ai_model' => $d['model'] ?? null])->save();

            return;
        }
        $mail->forceFill(['method' => $d['method'], 'confidence' => $d['confidence'], 'ai_model' => $d['model'] ?? null])->save();
        self::route($mail, $d['category'], $d['area'] ?? null, $d['party_id'] ?? null, $d['related'] ?? null);
    }

    /**
     * @return array{category: ?string, area?: ?string, confidence?: ?float, method?: ?string, reason?: string, suggested?: ?string, party_id?: ?int, related?: ?Model, model?: ?string}
     */
    public static function classify(InboundMail $mail, ?MimeMessage $msg = null): array
    {
        $teamId = $mail->team_id;
        $signals = array_fill_keys(array_keys(self::LEVELS), []);

        // 1. Reply threading: In-Reply-To / References point to a message we sent.
        $threadIds = self::messageIds($mail->in_reply_to.' '.$mail->references);
        $comIds = array_filter(array_map(fn (string $id) => preg_match('/^<clarto-com-(\d+)@/', $id, $m) ? (int) $m[1] : null, $threadIds));
        foreach (Communication::query()->where('team_id', $teamId)->where('direction', Communication::OUT)->whereKey($comIds ?: [0])->get() as $c) {
            if ($c->related_type === (new CollaboratorNotice)->getMorphClass()) {
                $notice = CollaboratorNotice::query()->find($c->related_id);
                $signals['hilo'][] = ['category' => $notice?->subject_type === (new SearchRequest)->getMorphClass() ? 'solicitud' : 'colaborador'];
            } elseif ($c->related_type === (new ValuationRequest)->getMorphClass()) {
                $signals['hilo'][] = ['category' => 'propietario', 'related' => ValuationRequest::query()->find($c->related_id), 'party_id' => $c->party_id];
            } elseif ($c->party_id !== null) {
                $signals['hilo'][] = ['category' => self::isOwner((int) $c->party_id) ? 'propietario' : 'cliente', 'party_id' => (int) $c->party_id];
            }
        }
        foreach ($threadIds as $id) {
            if (preg_match('/^<clarto-val-(VAL-\d+)\./i', $id, $m) && ($v = ValuationRequest::query()->where('team_id', $teamId)->where('reference', strtoupper($m[1]))->first())) {
                $signals['hilo'][] = ['category' => 'propietario', 'related' => $v];
            }
        }

        // 2. References in subject or text.
        $haystack = $mail->subject."\n".$mail->text_body;
        preg_match_all(SearchRequests::REFERENCE_PATTERN, $haystack, $sol);
        if ($sol[0] !== [] && SearchRequest::query()->forTeam($teamId)->whereIn('reference', array_map('strtoupper', $sol[0]))->exists()) {
            $signals['referencia'][] = ['category' => 'solicitud'];
        }
        preg_match_all(self::VALUATION_PATTERN, $haystack, $val);
        foreach (ValuationRequest::query()->where('team_id', $teamId)->whereIn('reference', array_map('strtoupper', $val[0] ?: ['-']))->get() as $v) {
            $signals['referencia'][] = ['category' => 'propietario', 'related' => $v];
        }

        // 3. Staff rules (corrections).
        if ($rule = InboundMailRule::match($teamId, $mail->from_email)) {
            $signals['regla'][] = ['category' => $rule->category, 'area' => $rule->area];
        }

        // 4. Known sender: collaborator, client / owner, valuation requester (GHL contact).
        if (filled($mail->from_email)) {
            if ($collaborator = Collaborator::findByAddress($teamId, null, $mail->from_email)) {
                $withRequests = SearchRequestRecipient::query()->where('collaborator_id', $collaborator->getKey())->whereIn('status', ['enviada', 'respondida'])->exists();
                $signals['remitente'][] = ['category' => $withRequests ? 'solicitud' : 'colaborador'];
            }
            if ($partyId = Communications::partyFor($teamId, null, $mail->from_email)) {
                $signals['remitente'][] = ['category' => self::isOwner($partyId) ? 'propietario' : 'cliente', 'party_id' => $partyId];
            }
            if ($v = ValuationRequest::query()->where('team_id', $teamId)->where('contact->email', $mail->from_email)->latest('id')->first()) {
                $signals['remitente'][] = ['category' => 'propietario', 'related' => $v];
            }
        }

        // 5. Headers of mass mailings and spam filters.
        $spam = preg_match('/^\s*yes/i', (string) $msg?->header('X-Spam-Flag')) || preg_match('/^\s*yes/i', (string) $msg?->header('X-Spam-Status'));
        if ($spam || filled($msg?->header('List-Unsubscribe')) || preg_match('/^\s*(bulk|list|junk)\b/i', (string) $msg?->header('Precedence'))) {
            $signals['cabeceras'][] = ['category' => 'publicidad'];
        }

        foreach ($signals as $level => $found) {
            if ($found === []) {
                continue;
            }
            $categories = array_values(array_unique(array_column($found, 'category')));
            if (in_array('propietario', $categories, true) && in_array('cliente', $categories, true)) {
                $categories = array_values(array_diff($categories, ['cliente']));   // the same person seen as client and owner: owner
            }
            if (count($categories) > 1) {
                return ['category' => null, 'reason' => 'conflicto', 'suggested' => $categories[0], 'method' => $level];
            }
            $pick = fn (string $key) => collect($found)->pluck($key)->filter()->first();

            return ['category' => $categories[0], 'area' => $pick('area'), 'confidence' => self::LEVELS[$level], 'method' => $level,
                'party_id' => $pick('party_id') ?? ($pick('related') instanceof ValuationRequest && filled($mail->from_email) ? Communications::partyFor($teamId, null, $mail->from_email) : null),
                'related' => $pick('related')];
        }

        return $mail->is_auto ? ['category' => 'automatico'] : self::askAi($mail);
    }

    /** @return array{category: ?string, area?: ?string, confidence?: ?float, method?: string, reason?: string, suggested?: ?string, model?: ?string} */
    private static function askAi(InboundMail $mail): array
    {
        $settings = InboundMailSettings::forTeam($mail->team_id);
        if (! $settings->ai) {
            return ['category' => null, 'reason' => 'sin_ia'];
        }
        if (Cache::has(self::AI_PAUSE)) {
            return ['category' => null, 'reason' => 'ia_no_disponible'];   // retried by the next polls (retryPending)
        }
        $categories = array_values(array_diff(InboundMail::CATEGORIES, ['automatico']));
        $system = 'Clasificas correos que llegan al buzón general de CLARTO (abogados, consultores y gestores con área inmobiliaria). '
            .'Responde SOLO con JSON: {"categoria": uno de ['.implode(', ', $categories).'], "area": uno de ['.implode(', ', InboundMail::AREAS).'] o null, "confianza": número de 0 a 1}. '
            .'solicitud = respuesta de un colaborador a una búsqueda de inmueble; propietario = propietario que responde sobre su valoración o inmueble; '
            .'cliente = cliente con un asunto en curso; consulta = petición nueva de alguien (indica el área); proveedor = proveedor o factura; '
            .'administracion = notificación oficial (AEAT, Seguridad Social, juzgado, registro, ayuntamiento); publicidad = publicidad o spam; otros = lo demás.';
        $user = 'Asunto: '.self::redact((string) $mail->subject)."\nExtracto: ".self::excerpt((string) $mail->text_body);

        try {
            $reply = app(MailClassifier::class)->complete($system, $user, $settings->ai_model);
        } catch (AiQuotaExceeded $e) {
            Cache::put(self::AI_PAUSE, true, now()->addMinutes(max(1, (int) config('correo-entrante.ai_pause_minutes', 10))));

            return ['category' => null, 'reason' => 'ia_no_disponible'];
        } catch (Throwable $e) {
            report($e);

            return ['category' => null, 'reason' => 'ia_no_disponible'];
        }
        $json = preg_match('/\{.*\}/s', $reply['content'], $m) ? json_decode($m[0], true) : null;
        $category = is_array($json) ? Str::lower((string) ($json['categoria'] ?? '')) : '';
        $area = is_array($json) && in_array(Str::lower((string) ($json['area'] ?? '')), InboundMail::AREAS, true) ? Str::lower((string) $json['area']) : null;
        $confidence = is_array($json) && is_numeric($json['confianza'] ?? null) ? max(0.0, min(1.0, (float) $json['confianza'])) : 0.0;
        $base = ['area' => $area, 'confidence' => $confidence, 'method' => 'ia', 'model' => Str::limit($reply['model'], 115, '')];
        if (! in_array($category, $categories, true)) {
            return ['category' => null, 'reason' => 'ia_sin_respuesta'] + $base;
        }
        if (in_array($category, ['solicitud', 'propietario', 'cliente', 'colaborador'], true)) {
            return ['category' => null, 'reason' => 'sin_expediente', 'suggested' => $category] + $base;   // the AI cannot know the record
        }
        if ($confidence < self::MIN_CONFIDENCE) {
            return ['category' => null, 'reason' => 'baja_confianza', 'suggested' => $category] + $base;
        }

        return ['category' => $category] + $base;
    }

    /** What the AI sees of the body: the first lines of the new text (no quoted reply), personal data replaced. */
    public static function excerpt(string $text, int $length = 500): string
    {
        $text = (string) preg_replace('/(^|\n)(El .{3,200} escribi[oó]:|On .{3,200} wrote:|-{2,} ?(Original Message|Mensaje original|Forwarded message|Mensaje reenviado).*)[\s\S]*$/u', '', $text);
        $lines = array_filter(explode("\n", $text), fn (string $l) => ! str_starts_with(ltrim($l), '>'));

        return Str::limit(self::redact(trim((string) preg_replace('/\s+/u', ' ', implode(' ', $lines)))), $length);
    }

    public static function redact(string $text): string
    {
        return (string) preg_replace([
            '/[\w.+-]+@[\w-]+(\.[\w-]+)+/u',                              // e-mail addresses
            '#\b(https?://|www\.)\S+#i',                                    // links
            '/\b[A-Z]{2}\d{2}(?:[ ]?[A-Z0-9]{4}){3,7}(?:[ ]?[A-Z0-9]{1,4})?\b/i',   // IBAN
            '/\b[XYZ]?\d{7,8}[ -]?[A-Z]\b/i',                               // DNI / NIE
            '/\b[A-HJ-NP-SUVW]\d{7}[0-9A-J]\b/i',                           // CIF
            '/(\+|00)?\d[\d .-]{7,}\d/',                                    // phones and long numbers
        ], ['[correo]', '[enlace]', '[iban]', '[documento]', '[documento]', '[número]'], $text);
    }

    // ── Routing ─────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Sends a message where its category says. Returns false when it cannot be routed (it then waits for review).
     */
    public static function route(InboundMail $mail, string $category, ?string $area = null, ?int $partyId = null, ?Model $related = null, ?Authenticatable $user = null): bool
    {
        $by = $user ? ['reviewed_by' => $user->getAuthIdentifier(), 'reviewed_at' => now()] : [];
        $done = fn (array $f) => $mail->forceFill($f + ['category' => $category, 'reason' => null] + $by)->save();

        switch ($category) {
            case 'solicitud':
                $result = SearchRequests::enabled() ? self::toRequests($mail) : 'modulo_desactivado';
                if (in_array($result, ['ignorada', 'modulo_desactivado'], true)) {
                    return self::review($mail, $result === 'ignorada' ? 'solicitud_sin_vincular' : 'solicitudes_desactivado', $category, $area);
                }
                $done(['status' => 'enrutado', 'route' => 'solicitud_'.$result, 'communication_id' => self::communicationId($mail)]);

                return true;

            case 'propietario':
            case 'cliente':
                $partyId ??= Communications::partyFor($mail->team_id, null, $mail->from_email);
                if ($partyId === null && $related === null) {
                    return self::review($mail, 'sin_expediente', $category, $area);
                }
                $c = self::toTimeline($mail, $partyId, $related);
                $done(['status' => 'enrutado', 'route' => 'cronologia', 'communication_id' => $c?->getKey(), 'party_id' => $partyId,
                    'related_type' => $related?->getMorphClass(), 'related_id' => $related ? (string) $related->getKey() : null]);

                return true;

            case 'publicidad':
            case 'automatico':
                $done(['status' => 'archivado', 'route' => 'archivo', 'area' => null]);

                return true;

            default:   // consulta, colaborador, proveedor, administracion, otros → «Entrada»
                $area = match ($category) {
                    'consulta' => $area ?? 'otros', 'colaborador' => 'inmobiliaria', default => null,
                };
                $done(['status' => 'entrada', 'route' => 'entrada', 'area' => $area]);
                self::entryTask($mail);

                return true;
        }
    }

    // ── Staff actions ───────────────────────────────────────────────────────────────────────────────────────────

    /** «Confirmar»: the suggestion is right. */
    public static function confirm(InboundMail $mail, Authenticatable $user): void
    {
        throw_if(blank($mail->suggested_category), new RuntimeException(__('correo.error.sin_sugerencia')));
        self::correct($mail, (string) $mail->suggested_category, $mail->suggested_area, null, null, $user);
    }

    /**
     * «Corregir» (review queue, «Entrada» or archive): routes it with the category chosen by staff and, with
     * $ruleScope, remembers it for this sender or domain.
     */
    public static function correct(InboundMail $mail, string $category, ?string $area, ?int $partyId, ?string $ruleScope, Authenticatable $user): void
    {
        throw_unless(in_array($category, InboundMail::CATEGORIES, true) && ($area === null || in_array($area, InboundMail::AREAS, true)), new RuntimeException(__('correo.error.categoria')));
        throw_if($partyId !== null && ! Party::query()->forTeam($mail->team_id)->whereKey($partyId)->exists(), new RuntimeException(__('correo.error.contacto')));
        throw_if($mail->status === 'enrutado', new RuntimeException(__('correo.error.ya_enrutado')));   // already in a request or a timeline
        $domain = substr(strrchr((string) $mail->from_email, '@') ?: '', 1);
        throw_if($ruleScope === InboundMailRule::DOMAIN && in_array($domain, InboundMailRule::PUBLIC_DOMAINS, true), new RuntimeException(__('correo.error.dominio_publico')));
        $related = $mail->related_type === (new ValuationRequest)->getMorphClass() ? ValuationRequest::query()->find($mail->related_id) : null;
        if (! self::route($mail->fresh(), $category, $area, $partyId, $related, $user)) {
            throw new RuntimeException(__('correo.motivo.'.$mail->fresh()->reason));
        }
        $mail->refresh()->forceFill(['method' => 'manual', 'confidence' => 1])->save();
        if ($ruleScope !== null && filled($mail->from_email) && $category !== 'automatico') {
            InboundMailRule::query()->updateOrCreate(['team_id' => $mail->team_id, 'kind' => $ruleScope, 'value' => $ruleScope === InboundMailRule::DOMAIN ? $domain : $mail->from_email],
                ['category' => $category, 'area' => $area, 'created_by' => $user->getAuthIdentifier()]);
        }
    }

    /** «Atendido»: an «Entrada» item somebody took care of. */
    public static function markHandled(InboundMail $mail, Authenticatable $user): void
    {
        $mail->forceFill(['status' => 'atendido', 'reviewed_by' => $user->getAuthIdentifier(), 'reviewed_at' => now()])->save();
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────────────────────────────────────

    /** MessageSending: our e-mails carry a Message-ID we recognise in replies (communication or valuation). */
    public static function stampMessageId(MessageSending $event): void
    {
        $headers = $event->message->getHeaders();
        $value = trim((string) $headers->get(Communications::MAIL_HEADER)?->getBodyAsString());
        if ($value === '' || $headers->has('Message-ID')) {
            return;
        }
        if (ctype_digit($value)) {
            $headers->addIdHeader('Message-ID', SearchRequests::messageIdFor((int) $value));
        } elseif (preg_match('/^valoracion-(VAL-\d+)$/i', $value, $m)) {
            $headers->addIdHeader('Message-ID', 'clarto-val-'.strtoupper($m[1]).'.'.Str::lower(Str::random(12)).'@'.Str::after(SearchRequests::messageIdFor(0), '@'));
        }
    }

    public static function isAutomatic(MimeMessage $msg, ?string $from): bool
    {
        $auto = Str::lower(trim((string) $msg->header('Auto-Submitted')));
        $type = Str::lower((string) $msg->header('Content-Type'));

        return ($auto !== '' && $auto !== 'no')
            || filled($msg->header('X-Autoreply')) || filled($msg->header('X-Autorespond'))
            || (bool) preg_match('/^\s*auto[_-]?reply/i', (string) $msg->header('Precedence'))
            || str_contains($type, 'multipart/report') || str_contains($type, 'disposition-notification') || str_contains($type, 'delivery-status')
            || (bool) preg_match('/^(mailer-daemon|postmaster|no-?reply-?dsn)@/i', (string) $from)
            || (bool) preg_match('/^\s*(le[ií]do|read|entregado|delivered|undeliverable|undelivered|no entregado|mail delivery failed|returned mail|delivery status notification|respuesta autom[aá]tica|automatic reply|auto(matic)?[- ]?reply|out of office|fuera de la oficina|ausente)\b/iu', (string) $msg->header('Subject'));
    }

    public static function sanitize(string $html): string
    {
        $config = (new HtmlSanitizerConfig)->allowSafeElements()->allowLinkSchemes(['https', 'http', 'mailto'])
            ->allowMediaSchemes(['data', 'cid'])->allowRelativeMedias(false)->allowRelativeLinks(false)
            ->forceAttribute('a', 'rel', 'noopener noreferrer nofollow')->forceAttribute('a', 'target', '_blank')->withMaxInputLength(2_000_000);

        return (new HtmlSanitizer($config))->sanitize($html);
    }

    /** Deletes copies past the retention (advertising and automatic ones after SHORT_RETENTION days). */
    public static function purge(InboundMailSettings $s): int
    {
        $n = 0;
        InboundMail::query()->where('team_id', $s->team_id)
            ->where(fn ($q) => $q->where('created_at', '<', now()->subDays(max(30, (int) $s->retention_days)))
                ->orWhere(fn ($q) => $q->whereIn('category', ['publicidad', 'automatico'])->where('created_at', '<', now()->subDays(InboundMailSettings::SHORT_RETENTION))))
            ->lazyById()->each(function (InboundMail $m) use (&$n): void {
                $m->delete();
                $n++;
            });

        return $n;
    }

    private static function review(InboundMail $mail, string $reason, ?string $suggested, ?string $area): bool
    {
        $mail->forceFill(['status' => 'revisar', 'reason' => $reason, 'suggested_category' => $suggested, 'suggested_area' => $area])->save();

        return false;
    }

    private static function toRequests(InboundMail $mail): string
    {
        return SearchRequests::inboundMail([
            'from' => (string) $mail->from_email, 'subject' => $mail->subject, 'text' => $mail->text_body, 'message_id' => $mail->message_id,
            'in_reply_to' => $mail->in_reply_to, 'references' => $mail->references, 'auto_submitted' => $mail->is_auto ? 'auto-replied' : null,
        ], $mail->team_id);
    }

    private static function toTimeline(InboundMail $mail, ?int $partyId, ?Model $related): ?Communication
    {
        $key = self::idempotencyKey($mail);
        if ($c = Communication::query()->where('idempotency_key', $key)->first()) {
            return $c;
        }
        $text = trim((string) $mail->text_body);

        return Communications::record($mail->team_id, 'email', Communication::IN, array_filter([
            'email' => $mail->from_email, 'subject' => Str::limit((string) $mail->subject, 240) ?: null,
            'body' => $text !== '' ? Str::limit($text, 20000) : '['.__('Message without text').']', 'status' => 'recibido', 'sent_at' => $mail->sent_at ?? now(),
            'source' => 'correo', 'idempotency_key' => $key, 'provider_id' => $mail->message_id ? Str::limit($mail->message_id, 180, '') : null,
            'party_id' => $partyId, 'related' => $related,
        ], fn ($v) => $v !== null));
    }

    /** Same key as the «Solicitudes» forwarder: a message seen by both paths is logged once. */
    private static function idempotencyKey(InboundMail $mail): string
    {
        return 'in-mail:'.$mail->team_id.':'.sha1($mail->message_id ?? Str::lower(trim((string) $mail->from_email)).trim((string) $mail->subject).trim((string) $mail->text_body));
    }

    private static function communicationId(InboundMail $mail): ?int
    {
        $id = Communication::query()->where('idempotency_key', self::idempotencyKey($mail))->value('id');

        return $id === null ? null : (int) $id;
    }

    private static function entryTask(InboundMail $mail): void
    {
        $settings = InboundMailSettings::forTeam($mail->team_id);
        $team = Team::query()->find($mail->team_id);
        $owner = $settings->ownerFor($mail->area ?? $mail->category) ?? User::query()->whereKey($team?->user_id)->value('email');
        $url = rescue(fn () => InboundMailInbox::getUrl(panel: 'admin', tenant: $team), url('/admin'), report: false);
        // Only the category, area and number leave Liberu (no sender, no subject).
        rescue(fn () => TarmioTask::sync($mail, 'correo_entrada', 'Correo entrante · '.$mail->categoryLabel().($mail->area ? ' · '.$mail->areaLabel() : '').' · #'.$mail->getKey(),
            $owner, now()->addWeekday(), $url), report: true);
    }

    private static function isOwner(int $partyId): bool
    {
        return OwnerProfile::query()->where('party_id', $partyId)->exists()
            || Party::query()->whereKey($partyId)->get()->contains(fn (Party $p) => in_array($p->getRawOriginal('type'), ['vendor', 'landlord'], true));
    }

    /** @return list<string> "<id@host>" values */
    private static function messageIds(string $headers): array
    {
        preg_match_all('/<[^<>\s]+>/', $headers, $m);

        return array_values(array_unique($m[0]));
    }

    private static function firstId(string $header): ?string
    {
        return self::messageIds($header)[0] ?? (trim($header) !== '' ? '<'.trim($header, " <>\t").'>' : null);
    }
}
