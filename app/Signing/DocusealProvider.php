<?php

declare(strict_types=1);

namespace App\Signing;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * DocuSeal cloud (sandbox: api.docuseal.com; production: api.docuseal.eu, the only one with AdES). Header
 * X-Auth-Token. The signature level is decided by the DocuSeal template (AdES = a template with "Verify ID" set to
 * AES on docuseal.eu); there is no API parameter for it. Per-signer phone OTP (require_phone_2fa) is sent by
 * DocuSeal itself.
 */
final class DocusealProvider implements SignatureProvider
{
    private string $environment;

    public function __construct()
    {
        $this->environment = config('firma.docuseal.environment') === 'production' ? 'production' : 'sandbox';
    }

    public function name(): string
    {
        return 'docuseal';
    }

    public function environment(): string
    {
        return $this->environment;
    }

    public function supportsLevel(string $level): bool
    {
        return $level === 'simple' || ($level === 'avanzada' && $this->environment === 'production');
    }

    public function create(SignatureRequest $request, ?string $pdf): array
    {
        $signers = $request->signers()->get();
        $values = (array) $request->values;
        $fields = (array) ($request->template_snapshot['fields'] ?? []);

        // Retry after an uncertain failure: the submission may already exist (DocuSeal has no Idempotency-Key).
        $existing = $this->get('/submitters', ['external_id' => $signers->first()->external_id, 'limit' => 5]);
        foreach ((array) ($existing['data'] ?? []) as $s) {
            if (($s['external_id'] ?? null) === $signers->first()->external_id && isset($s['submission_id'])) {
                return $this->normalizeCreated($this->get('/submissions/'.$this->id((string) $s['submission_id'])));
            }
        }

        $submitters = $signers->map(function (SignatureSigner $signer) use ($values, $fields): array {
            $own = [];
            foreach ($fields as $f) {
                if (($f['role'] ?? null) === $signer->role && filled($f['docuseal_field'] ?? null) && filled($values[$f['key']] ?? null)) {
                    $own[$f['docuseal_field']] = (string) $values[$f['key']];
                }
            }

            return array_filter([
                'role' => $signer->provider_role ?: $signer->role,
                'name' => $signer->name,
                'email' => $signer->email,
                'phone' => $signer->phone ? '+'.ltrim($signer->phone, '+') : null,
                'external_id' => $signer->external_id,
                'values' => $own ?: null,
                'require_phone_2fa' => $signer->require_phone_2fa ?: null,
            ], fn ($v) => $v !== null);
        })->values()->all();

        $body = ['send_email' => (bool) config('firma.docuseal.send_email'), 'order' => 'preserved', 'submitters' => $submitters];
        if ($pdf !== null) {
            $path = '/submissions/pdf';
            $body += ['name' => $request->title, 'documents' => [['name' => $request->title, 'file' => base64_encode($pdf)]]];
        } else {
            $path = '/submissions';
            $templateId = $request->template?->docusealTemplateId($this->environment)
                ?? (isset($request->template_snapshot['docuseal_template_ids'][$this->environment]) ? (int) $request->template_snapshot['docuseal_template_ids'][$this->environment] : null);
            if (! $templateId) {
                throw new SigningFailed(__('The template has no DocuSeal template id for the :env environment.', ['env' => $this->environment]));
            }
            $body['template_id'] = $templateId;
        }

        return $this->normalizeCreated($this->send('post', $path, $body));
    }

    public function fetch(string $submissionId): array
    {
        $s = $this->get('/submissions/'.$this->id($submissionId));
        $verified = [];
        foreach ((array) ($s['submission_events'] ?? []) as $e) {
            // [NOT VERIFIED with the real account] event name for a confirmed phone code.
            if (in_array($e['event_type'] ?? '', ['phone_verified', 'complete_verification'], true) && isset($e['submitter_id'])) {
                $verified[(string) $e['submitter_id']] = $e['event_timestamp'] ?? $e['created_at'] ?? null;
            }
        }

        $submitters = array_map(fn (array $x): array => [
            'external_id' => $x['external_id'] ?? null,
            'id' => isset($x['id']) ? (string) $x['id'] : null,
            'status' => match (true) {
                filled($x['completed_at'] ?? null) || ($x['status'] ?? '') === 'completed' => 'firmado',
                filled($x['declined_at'] ?? null) || ($x['status'] ?? '') === 'declined' => 'rechazado',
                filled($x['opened_at'] ?? null) || ($x['status'] ?? '') === 'opened' => 'visto',
                filled($x['sent_at'] ?? null) || ($x['status'] ?? '') === 'sent' => 'enviado',
                default => 'pendiente',
            },
            'sent_at' => $x['sent_at'] ?? null,
            'viewed_at' => $x['opened_at'] ?? null,
            'signed_at' => $x['completed_at'] ?? null,
            'declined_at' => $x['declined_at'] ?? null,
            'otp_verified_at' => isset($x['id']) ? ($verified[(string) $x['id']] ?? null) : null,
        ], (array) ($s['submitters'] ?? []));

        $all = array_column($submitters, 'status');
        $status = match (true) {
            $all !== [] && count(array_filter($all, fn ($v) => $v !== 'firmado')) === 0 => 'completada',
            in_array('rechazado', $all, true) || ($s['status'] ?? '') === 'declined' => 'rechazada',
            filled($s['archived_at'] ?? null) => 'anulada',
            ($s['status'] ?? '') === 'expired' => 'caducada',
            in_array('visto', $all, true) || in_array('firmado', $all, true) => 'en_curso',
            default => 'enviada',
        };

        return ['status' => $status, 'submitters' => $submitters, 'completed_at' => $s['completed_at'] ?? null, 'audit_log_url' => $s['audit_log_url'] ?? null];
    }

    public function documents(string $submissionId): array
    {
        $out = [];
        foreach ((array) ($this->get('/submissions/'.$this->id($submissionId).'/documents')['documents'] ?? []) as $d) {
            if (filled($d['url'] ?? null)) {
                $out[] = ['kind' => 'firmado', 'name' => (string) ($d['name'] ?? 'documento'), 'url' => (string) $d['url']];
            }
        }
        $audit = $this->fetch($submissionId)['audit_log_url'] ?? null;
        if (filled($audit)) {
            $out[] = ['kind' => 'auditoria', 'name' => 'registro-de-auditoria', 'url' => (string) $audit];
        }

        return $out;
    }

    /** Downloads without the API token, only from DocuSeal hosts over https (manual redirects, each one checked). */
    public function download(string $url): string
    {
        for ($hop = 0; $hop < 4; $hop++) {
            $parts = parse_url($url) ?: [];
            $host = strtolower((string) ($parts['host'] ?? ''));
            $allowed = collect((array) config('firma.docuseal.download_hosts'))->contains(fn (string $h): bool => $host === $h || str_ends_with($host, '.'.$h));
            if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['port']) || ! $allowed) {
                throw new SigningFailed(__('Download refused: the document is not on a DocuSeal host.'));
            }
            try {
                $r = Http::timeout(60)->withOptions(['allow_redirects' => false])->get($url);
            } catch (ConnectionException) {
                throw new SigningFailed(__('DocuSeal did not answer.'));
            }
            if ($r->redirect() && filled($r->header('Location'))) {
                $url = (string) $r->header('Location');

                continue;
            }
            if (! $r->successful()) {
                throw new SigningFailed(__('DocuSeal answered :status.', ['status' => $r->status()]));
            }

            return $r->body();
        }

        throw new SigningFailed(__('Too many redirects.'));
    }

    public function verifyWebhook(string $rawBody, ?string $signature): bool
    {
        $secret = (string) config('firma.docuseal.webhook_secret');
        if ($secret === '' || ! preg_match('/^(\d{1,12})\.([0-9a-f]{64})$/', (string) $signature, $m)) {
            return false;
        }
        if (abs(time() - (int) $m[1]) > (int) config('firma.docuseal.webhook_tolerance', 300)) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $m[1].'.'.$rawBody, $secret), $m[2]);
    }

    /** @return array{submission_id: string, submitters: list<array{external_id: string, id: ?string, url: ?string}>} */
    private function normalizeCreated(mixed $j): array
    {
        $list = is_array($j) && array_is_list($j) ? $j : (array) ($j['submitters'] ?? []);
        $id = is_array($j) && array_is_list($j) ? ($j[0]['submission_id'] ?? null) : ($j['id'] ?? null);
        if ($id === null) {
            throw new SigningFailed(__('Unexpected answer from DocuSeal.'));
        }
        $base = $this->environment === 'production' ? 'https://docuseal.eu' : 'https://docuseal.com';

        return [
            'submission_id' => (string) $id,
            'submitters' => array_map(fn (array $s): array => [
                'external_id' => (string) ($s['external_id'] ?? ''),
                'id' => isset($s['id']) ? (string) $s['id'] : null,
                'url' => $s['embed_src'] ?? (isset($s['slug']) ? $base.'/s/'.$s['slug'] : null),
            ], $list),
        ];
    }

    private function id(string $id): string
    {
        if (! preg_match('/^\d{1,18}$/', $id)) {
            throw new SigningFailed(__('Invalid DocuSeal id.'));
        }

        return $id;
    }

    /** @param array<string, mixed> $query */
    private function get(string $path, array $query = []): array
    {
        return (array) $this->send('get', $path, $query);
    }

    /** @param array<string, mixed> $data */
    private function send(string $method, string $path, array $data): mixed
    {
        $key = (string) config('firma.docuseal.api_key');
        if ($key === '') {
            throw new SigningFailed(__('DocuSeal is not configured on the server (API key missing).'));
        }

        try {
            /** @var Response $r */
            $r = $this->http($key)->{$method}($path, $data);
        } catch (ConnectionException) {
            throw new SigningFailed(__('DocuSeal did not answer.'));
        }
        if (! $r->successful()) {
            throw new SigningFailed(__('DocuSeal answered :status.', ['status' => $r->status()]));   // the body is not propagated
        }

        return $r->json();
    }

    private function http(string $key): PendingRequest
    {
        return Http::baseUrl((string) config('firma.docuseal.urls.'.$this->environment))
            ->withHeaders(['X-Auth-Token' => $key])->acceptJson()->asJson()
            ->withOptions(['allow_redirects' => false])->timeout(20);
    }
}
