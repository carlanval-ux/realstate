<?php

declare(strict_types=1);

namespace App\Signing;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Liberu\RealEstate\Instructions\Models\Instruction;
use Liberu\RealEstate\Parties\Models\Party;
use Liberu\RealEstate\Properties\Models\Property;
use Throwable;

/**
 * The signing flow: prefill a «Formulario» from the owner/property/mandate data, show what is missing, render the
 * preview, send to one or several signers, reconcile the state with the provider (webhooks are only notices), store
 * the signed PDF and the audit log privately, and send the link or manual reminders by SMS.
 */
final class Signing
{
    public function __construct(private SignatureProvider $provider, private SmsGateway $sms) {}

    public static function make(): self
    {
        return app(self::class);
    }

    // ── Data map ────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Owner (propietario.*), property (inmueble.*), mandate (encargo.*) and today (hoy) as one array the template
     * fields read with dot paths. The owner falls back to the mandate's party.
     *
     * @return array<string, mixed>
     */
    public static function context(?Party $party, ?Property $property, ?Instruction $instruction): array
    {
        $instruction ??= $property ? Instruction::query()->where('property_id', $property->getKey())->latest('id')->first() : null;
        $party ??= $instruction?->party_id ? Party::query()->find($instruction->party_id) : null;
        $property ??= $instruction?->property_id ? Property::query()->find($instruction->property_id) : null;

        $inmueble = $property ? $property->attributesToArray() + [
            'referencia' => $property->reference(),
            'municipio' => $property->city?->name,
            'operacion' => in_array($property->deal_type?->value ?? $property->deal_type, ['rent', 'daily'], true) ? 'alquiler' : 'venta',
        ] : [];

        // Owner 360 profile (Propietarios: NIF, fiscal address, representative…) when that feature is installed; never the IBAN.
        $profile = $party && class_exists(\App\Owners\OwnerProfile::class)
            ? \App\Owners\OwnerProfile::query()->where('party_id', $party->getKey())->first()?->makeHidden(['iban'])->attributesToArray()
            : null;

        return [
            'propietario' => ($party?->attributesToArray() ?? []) + ($profile ? ['perfil' => array_diff_key($profile, ['iban' => 1])] : []),
            'inmueble' => $inmueble,
            'encargo' => $instruction?->attributesToArray() ?? [],
            'hoy' => now('Europe/Madrid')->format('d/m/Y'),
            '_models' => ['party' => $party, 'property' => $property, 'instruction' => $instruction],
        ];
    }

    /**
     * Values of the template's fields from the context, and the labels of the required ones still empty.
     *
     * @param  array<string, mixed>  $context
     * @return array{values: array<string, ?string>, missing: list<string>}
     */
    public static function prefill(FormTemplate $template, array $context): array
    {
        $values = [];
        foreach ((array) $template->fields as $field) {
            $value = null;
            foreach (array_filter(explode('|', (string) ($field['source'] ?? ''))) as $path) {   // "a|b": first one with a value
                if (filled($value = self::format(data_get($context, trim($path))))) {
                    break;
                }
            }
            $values[$field['key']] = $value;
        }

        return ['values' => $values, 'missing' => self::missing($template, $values)];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    public static function missing(FormTemplate $template, array $values): array
    {
        return array_values(array_map(
            fn (array $f): string => (string) ($f['label'] ?? $f['key']),
            array_filter((array) $template->fields, fn (array $f): bool => ($f['required'] ?? false) && blank($values[$f['key']] ?? null)),
        ));
    }

    /** Default signers from the template roles: "owner" → the owner, "staff" → the user sending. */
    public static function defaultSigners(?FormTemplate $template, array $context, ?object $user): array
    {
        $party = $context['_models']['party'] ?? null;
        $roles = $template?->roles ?: [['key' => 'propietario', 'label' => 'Propietario/a', 'from' => 'owner']];

        return array_map(fn (array $role): array => [
            'role' => $role['key'],
            'name' => ($role['from'] ?? '') === 'owner' ? $party?->name : (($role['from'] ?? '') === 'staff' ? $user?->name : null),
            'email' => ($role['from'] ?? '') === 'owner' ? $party?->email : (($role['from'] ?? '') === 'staff' ? $user?->email : null),
            'phone' => ($role['from'] ?? '') === 'owner' ? $party?->phone : null,
            'require_phone_2fa' => false,
        ], $roles);
    }

    /** The document as it will be sent: the uploaded PDF, the template's PDF, or the template text with the values. */
    public static function documentPdf(?FormTemplate $template, array $values, string $title, ?string $attachmentPath = null): string
    {
        $disk = Storage::disk(config('firma.disk'));
        if (filled($attachmentPath)) {
            return (string) $disk->get($attachmentPath);
        }
        if ($template?->source === 'pdf' && filled($template->pdf_path)) {
            return (string) $disk->get($template->pdf_path);
        }

        $labels = collect((array) $template?->fields)->pluck('label', 'key');
        $body = filled($template?->body)
            ? preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', fn (array $m): string => filled($values[$m[1]] ?? null) ? (string) $values[$m[1]] : '['.__('missing').': '.($labels[$m[1]] ?? $m[1]).']', (string) $template->body)
            : $labels->map(fn ($label, $key) => $label.': '.($values[$key] ?? ''))->implode("\n");

        return SimplePdf::make($title, (string) $body);
    }

    // ── Send ────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @param  array{template_id?: ?int, attachment?: ?string, title: string, party_id?: ?int, property_id?: ?int, instruction_id?: ?int, values?: array<string, mixed>, signature_level?: ?string, signers: list<array{role: string, name: ?string, email?: ?string, phone?: ?string, require_phone_2fa?: bool}>}  $input
     */
    public function send(array $input, ?int $actorId = null): SignatureRequest
    {
        $template = filled($input['template_id'] ?? null) ? FormTemplate::query()->findOrFail($input['template_id']) : null;
        $attachment = $input['attachment'] ?? null;
        if ($template === null && blank($attachment)) {
            throw new SigningFailed(__('Choose a template or attach a PDF.'));
        }
        if ($template !== null && $template->status !== 'activo') {
            throw new SigningFailed(__('Only active templates can be sent for signature.'));
        }
        $values = array_map(fn ($v): ?string => blank($v) ? null : trim((string) $v), (array) ($input['values'] ?? []));
        if ($template !== null && ($missing = self::missing($template, $values)) !== []) {
            throw new SigningFailed(__('Missing data: :fields', ['fields' => implode(', ', $missing)]));
        }
        $level = $template?->signature_level ?? ($input['signature_level'] ?? 'simple');
        if (! $this->provider->supportsLevel($level)) {
            throw new SigningFailed(__('The signature provider cannot deliver an advanced signature in this environment (only DocuSeal EU, production).'));
        }
        $signers = $this->signers($input['signers'] ?? [], $template);
        if ($this->provider->name() === 'docuseal' && $this->provider->environment() === 'sandbox') {
            self::ensureFictitious($signers);
        }

        $title = trim((string) $input['title']) ?: ($template?->name ?? __('Document'));
        $pdf = self::documentPdf($template, $values, $title, $attachment);
        if (! str_starts_with($pdf, '%PDF')) {
            throw new SigningFailed(__('The document is not a PDF.'));
        }
        $path = 'firma/enviados/'.Str::uuid().'.pdf';
        Storage::disk(config('firma.disk'))->put($path, $pdf);

        $ctx = self::context(
            filled($input['party_id'] ?? null) ? Party::query()->find($input['party_id']) : null,
            filled($input['property_id'] ?? null) ? Property::query()->find($input['property_id']) : null,
            filled($input['instruction_id'] ?? null) ? Instruction::query()->find($input['instruction_id']) : null,
        );
        $models = $ctx['_models'];

        $request = SignatureRequest::query()->create([
            'team_id' => $models['property']?->team_id ?? $models['party']?->team_id ?? $models['instruction']?->team_id,
            'form_template_id' => $template?->getKey(),
            'template_key' => $template?->key,
            'template_version' => $template?->version,
            'template_snapshot' => $template?->snapshot(),
            'title' => $title,
            'party_id' => $models['party']?->getKey(),
            'property_id' => $models['property']?->getKey(),
            'instruction_id' => $models['instruction']?->getKey(),
            'values' => $values,
            'document_path' => $path,
            'document_sha256' => hash('sha256', $pdf),
            'signature_level' => $level,
            'driver' => $this->provider->name(),
            'environment' => $this->provider->environment(),
            'status' => 'borrador',
            'created_by' => $actorId,
        ]);
        foreach ($signers as $i => $s) {
            $request->signers()->create($s + ['position' => $i, 'external_id' => 'clarto-'.Str::uuid()]);
        }
        if ($template !== null && $template->used_at === null) {
            $template->forceFill(['used_at' => now()])->save();   // from now on this version is immutable
        }

        // A provider template is filled by the provider; an uploaded/attached PDF is sent as is.
        $sendPdf = ($template === null || $template->source === 'pdf') ? $pdf : null;
        try {
            $created = $this->provider->create($request->load('template'), $sendPdf);
        } catch (Throwable $e) {
            $request->forceFill(['status' => 'error', 'last_error' => $e instanceof SigningFailed ? $e->getMessage() : __('The signature provider failed.')])->save();
            report($e);

            throw $e instanceof SigningFailed ? $e : new SigningFailed(__('The signature provider failed.'), 0, $e);
        }

        $byExternal = collect($created['submitters'])->keyBy('external_id');
        foreach ($request->signers as $signer) {
            $c = $byExternal[$signer->external_id] ?? null;
            $signer->forceFill(['provider_submitter_id' => $c['id'] ?? null, 'sign_url' => $c['url'] ?? null, 'status' => 'enviado', 'sent_at' => now()])->save();
        }
        $request->forceFill(['provider_submission_id' => $created['submission_id'], 'status' => 'enviada', 'sent_at' => now(), 'last_error' => null])->save();

        return $request->refresh();
    }

    // ── Reconciliation and webhooks ─────────────────────────────────────────────────────────────────────────────

    /** Brings the request to the provider's authoritative state; on completion stores the signed PDF and the audit log. */
    public function reconcile(SignatureRequest $request): SignatureRequest
    {
        if (blank($request->provider_submission_id)) {
            return $request;
        }
        $provider = $this->providerFor($request);

        return Cache::lock('firma-solicitud:'.$request->getKey(), 120)->block(30, function () use ($request, $provider): SignatureRequest {
            $request->refresh();
            $state = $provider->fetch($request->provider_submission_id);

            $signers = $request->signers()->get()->keyBy('external_id');
            foreach ($state['submitters'] as $s) {
                $signer = $signers[$s['external_id'] ?? ''] ?? $request->signers()->where('provider_submitter_id', $s['id'])->first();
                if ($signer === null) {
                    continue;
                }
                $changes = ['provider_submitter_id' => $signer->provider_submitter_id ?? $s['id']];
                if ((SignatureSigner::RANK[$s['status']] ?? 0) > (SignatureSigner::RANK[$signer->status] ?? 0)) {
                    $changes['status'] = $s['status'];
                }
                foreach (['viewed_at', 'signed_at', 'declined_at'] as $col) {
                    if ($signer->{$col} === null && filled($s[$col] ?? null)) {
                        $changes[$col] = $s[$col];
                    }
                }
                // Recorded only when the provider itself confirms the code (an SMS of ours never counts).
                if ($signer->otp_verified_at === null && filled($s['otp_verified_at'] ?? null)) {
                    $changes += ['otp_verified_at' => $s['otp_verified_at'], 'otp_channel' => 'sms'];
                }
                $signer->forceFill($changes)->save();
            }

            $rank = ['borrador' => 0, 'error' => 0, 'enviada' => 1, 'en_curso' => 2];
            if (! $request->isFinal() && ($rank[$state['status']] ?? 3) >= ($rank[$request->status] ?? 0)) {
                $request->status = $state['status'];
            }
            if ($request->status === 'completada' && $request->completed_at === null) {
                $request->completed_at = $state['completed_at'] ?? now();
            }
            $request->forceFill(['reconciled_at' => now()])->save();

            if ($request->status === 'completada') {
                $this->storeDocuments($request, $provider);
            }

            return $request->refresh();
        });
    }

    /**
     * DocuSeal webhook: signature check, one row per event (a repeated delivery does nothing), then reconciliation
     * with an authenticated GET. Answers the HTTP status to return (DocuSeal retries on 5xx).
     *
     * @return array{0: int, 1: string}
     */
    public static function handleDocusealWebhook(string $raw, ?string $signature): array
    {
        $provider = new DocusealProvider;
        if (! $provider->verifyWebhook($raw, $signature)) {
            return [401, 'no_autorizado'];
        }
        $event = json_decode($raw, true);
        $type = (string) ($event['event_type'] ?? '');
        if (! is_array($event) || $type === '' || str_starts_with($type, 'template.')) {
            return [200, 'ignorado'];
        }
        $data = (array) ($event['data'] ?? []);
        $submission = (string) (str_starts_with($type, 'form.') ? ($data['submission_id'] ?? $data['submission']['id'] ?? '') : ($data['id'] ?? ''));
        $key = hash('sha256', implode('|', [$type, $event['timestamp'] ?? '', $data['id'] ?? '', $submission]));

        $request = $submission === '' ? null : SignatureRequest::query()
            ->where('driver', 'docuseal')->where('environment', $provider->environment())->where('provider_submission_id', $submission)->first();

        try {
            $row = DB::transaction(fn () => SignatureEvent::query()->create([
                'provider' => 'docuseal', 'event_key' => $key, 'event_type' => mb_substr($type, 0, 60),
                'signature_request_id' => $request?->getKey(), 'payload' => self::redactEvent($event, $request !== null), 'result' => $request ? 'recibido' : 'huerfano',
            ]));
        } catch (UniqueConstraintViolationException) {
            return [200, 'duplicado'];
        }
        if ($request === null) {
            return [200, 'huerfano'];
        }

        try {
            (new self($provider, app(SmsGateway::class)))->reconcile($request);
            $row->forceFill(['result' => 'procesado'])->save();
        } catch (Throwable $e) {
            report($e);
            $row->delete();   // let DocuSeal's retry process it again

            return [503, 'reintentar'];
        }

        return [200, 'procesado'];
    }

    /**
     * What is kept of a DocuSeal event: never e-mails, IPs, browsers, values or document links (the state comes from
     * the reconciliation anyway). The DocuSeal account is shared with other companies of the group, whose events
     * also arrive here: of those (orphans) only the type and ids are kept.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private static function redactEvent(array $event, bool $ours): array
    {
        $data = (array) ($event['data'] ?? []);
        $keep = $ours ? ['id', 'submission_id', 'status', 'role', 'external_id', 'sent_at', 'opened_at', 'completed_at', 'declined_at'] : ['id', 'submission_id'];
        $kept = array_intersect_key($data, array_flip($keep));
        if (! isset($kept['submission_id']) && isset($data['submission']['id'])) {
            $kept['submission_id'] = $data['submission']['id'];
        }

        return ['event_type' => $event['event_type'] ?? null, 'timestamp' => $event['timestamp'] ?? null, 'data' => array_filter($kept, fn ($v) => is_scalar($v) || $v === null)];
    }

    /** Idempotent: a document already stored (same request, kind and name) is never downloaded or stored again. */
    private function storeDocuments(SignatureRequest $request, SignatureProvider $provider): void
    {
        foreach ($provider->documents($request->provider_submission_id) as $doc) {
            $name = mb_substr($doc['name'], 0, 200);
            if ($request->documents()->where('kind', $doc['kind'])->where('name', $name)->exists()) {
                continue;
            }
            $bytes = $provider->download($doc['url']);
            $path = 'firma/firmados/'.$request->getKey().'/'.$doc['kind'].'-'.Str::slug($name).'-'.Str::random(6).'.pdf';
            Storage::disk(config('firma.disk'))->put($path, $bytes);
            try {
                DB::transaction(fn () => $request->documents()->create(['kind' => $doc['kind'], 'name' => $name, 'path' => $path, 'sha256' => hash('sha256', $bytes), 'size' => strlen($bytes)]));
            } catch (UniqueConstraintViolationException) {
                Storage::disk(config('firma.disk'))->delete($path);
            }
        }
    }

    private function providerFor(SignatureRequest $request): SignatureProvider
    {
        $provider = $request->driver === 'docuseal' ? new DocusealProvider : new SimulatedSignatureProvider;
        if ($provider->environment() !== $request->environment) {
            throw new SigningFailed(__('This request was sent in another DocuSeal environment (:env).', ['env' => $request->environment]));
        }

        return $provider;
    }

    // ── SMS ─────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Signing link (kind "enlace") or manual reminder ("recordatorio") by SMS to one signer. Only to the test
     * destinations while FIRMA_SMS_ONLY_TEST_DESTINATIONS is on. Never changes the signature level.
     */
    public function sendSms(SignatureSigner $signer, string $kind, ?int $actorId = null): SmsMessage
    {
        $request = $signer->request;
        $to = self::phone($signer->phone);
        if ($to === null) {
            throw new SigningFailed(__('The signer has no valid mobile number.'));
        }
        if ($request->isFinal() || in_array($signer->status, ['firmado', 'rechazado'], true)) {
            throw new SigningFailed(__('There is nothing left to sign for this signer.'));
        }
        if (blank($signer->sign_url)) {
            throw new SigningFailed(__('The provider has not given a signing link for this signer (simulation or not sent).'));
        }

        $title = Str::ascii($request->title);
        $prefix = $kind === 'recordatorio' ? 'CLARTO: recordatorio, sigue pendiente su firma de "' : 'CLARTO: tiene un documento para firmar "';
        $suffix = '": ';
        $room = 160 - strlen($prefix) - strlen($suffix) - strlen($signer->sign_url);
        $text = $prefix.mb_strimwidth($title, 0, max(10, $room), '…').$suffix.$signer->sign_url;
        $logged = str_replace($signer->sign_url, '[enlace de firma]', $text);

        $blocked = config('firma.sms.only_test_destinations') && ! in_array($to, (array) config('firma.sms.test_destinations'), true);
        $result = $blocked
            ? ['status' => 'bloqueado', 'id' => null, 'error' => __('Not a test destination: real SMS to customers are not enabled yet.')]
            : $this->sms->send($to, $text, 'firma-'.$request->getKey().'-'.$signer->getKey());

        $sms = SmsMessage::query()->create([
            'signature_request_id' => $request->getKey(), 'signature_signer_id' => $signer->getKey(), 'kind' => $kind,
            'to' => $to, 'text' => $logged, 'driver' => $this->sms->name(), 'status' => $result['status'],
            'provider_message_id' => $result['id'], 'error' => $result['error'] ? mb_substr($result['error'], 0, 250) : null, 'sent_by' => $actorId,
        ]);
        // Client timeline (Comunicaciones): the signer's contact, link masked.
        \App\Communications\Communications::record($request->team_id, 'sms', \App\Models\Communication::OUT, [
            'phone' => '+'.$to, 'body' => $logged, 'status' => $result['status'] === 'aceptado' ? 'enviado' : 'error', 'error' => $sms->error,
            'provider_id' => $result['id'], 'purpose' => 'firma', 'source' => 'firma', 'user_id' => $actorId, 'related' => $request,
        ]);

        return $sms;
    }

    /** Spanish mobile/landline without prefix → 34…; otherwise digits only (international, no "+"). */
    public static function phone(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);
        if (str_starts_with((string) $phone, '00')) {
            $digits = substr($digits, 2);
        }
        if (preg_match('/^[6789]\d{8}$/', $digits)) {
            $digits = '34'.$digits;
        }

        return preg_match('/^\d{10,15}$/', $digits) ? $digits : null;
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * DocuSeal's test account lives in the global cloud (outside the EU): it only gets fictitious contacts. E-mails
     * of reserved domains (RFC 2606/6761) or a «+test» alias; mobiles only from the SMS test destinations.
     *
     * @param  list<array<string, mixed>>  $signers
     */
    private static function ensureFictitious(array $signers): void
    {
        foreach ($signers as $s) {
            $email = (string) ($s['email'] ?? '');
            $domain = Str::after($email, '@');
            $reserved = preg_match('/(^|\.)(example\.(com|net|org)|[a-z0-9-]+\.(test|example|invalid|localhost))$/', $domain)
                || in_array($domain, ['test', 'example', 'invalid', 'localhost'], true)
                || str_contains(Str::before($email, '@'), '+test');
            if ($email !== '' && ! $reserved) {
                throw new SigningFailed(__('DocuSeal test environment: only fictitious e-mails (example.com, .test or a +test alias), not :e.', ['e' => $email]));
            }
            if (filled($s['phone'] ?? null) && ! in_array($s['phone'], (array) config('firma.sms.test_destinations'), true)) {
                throw new SigningFailed(__('DocuSeal test environment: only the SMS test numbers, not :p.', ['p' => $s['phone']]));
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $signers
     * @return list<array<string, mixed>>
     */
    private function signers(array $signers, ?FormTemplate $template): array
    {
        $roles = collect((array) $template?->roles)->keyBy('key');
        $out = [];
        foreach ($signers as $s) {
            $email = Str::lower(trim((string) ($s['email'] ?? ''))) ?: null;
            $phone = filled($s['phone'] ?? null) ? self::phone($s['phone']) : null;
            if (blank($s['name'] ?? null) || ($email === null && $phone === null) || blank($s['role'] ?? null)) {
                throw new SigningFailed(__('Each signer needs a role, a name and an e-mail or mobile number.'));
            }
            if ($email !== null && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new SigningFailed(__('Invalid e-mail: :e', ['e' => $email]));
            }
            if (filled($s['phone'] ?? null) && $phone === null) {
                throw new SigningFailed(__('Invalid mobile number: :p', ['p' => $s['phone']]));
            }
            if (($s['require_phone_2fa'] ?? false) && $phone === null) {
                throw new SigningFailed(__('A code by SMS needs the signer\'s mobile number.'));
            }
            $out[] = [
                'role' => (string) $s['role'], 'provider_role' => $roles[$s['role']]['docuseal_role'] ?? null,
                'name' => trim((string) $s['name']), 'email' => $email, 'phone' => $phone,
                'require_phone_2fa' => (bool) ($s['require_phone_2fa'] ?? false),
            ];
        }
        if ($out === []) {
            throw new SigningFailed(__('Add at least one signer.'));
        }

        return $out;
    }

    private static function format(mixed $value): ?string
    {
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('d/m/Y');
        }
        if (is_array($value) || is_object($value)) {
            return null;
        }
        if (is_numeric($value) && str_contains((string) $value, '.')) {
            $f = (float) $value;

            return number_format($f, floor($f) == $f ? 0 : 2, ',', '.');
        }

        return blank($value) ? null : trim((string) $value);
    }
}
