<?php

declare(strict_types=1);

namespace App\Signing;

use Illuminate\Support\Str;

/**
 * Default driver while real signing is OFF: nothing leaves the server. A send gets a "SIM-" id and the signers stay
 * "sent"; no webhook can reach it, so a simulated request never completes on its own.
 */
final class SimulatedSignatureProvider implements SignatureProvider
{
    public function name(): string
    {
        return 'simulado';
    }

    public function environment(): ?string
    {
        return null;
    }

    public function supportsLevel(string $level): bool
    {
        return in_array($level, ['simple', 'avanzada'], true);
    }

    public function create(SignatureRequest $request, ?string $pdf): array
    {
        return [
            'submission_id' => 'SIM-'.Str::upper(Str::random(10)),
            'submitters' => $request->signers()->get()->map(fn (SignatureSigner $s): array => [
                'external_id' => $s->external_id, 'id' => null, 'url' => null,
            ])->all(),
        ];
    }

    public function fetch(string $submissionId): array
    {
        $request = SignatureRequest::query()->where('driver', 'simulado')->where('provider_submission_id', $submissionId)->firstOrFail();

        return [
            'status' => $request->status,
            'submitters' => $request->signers()->get()->map(fn (SignatureSigner $s): array => [
                'external_id' => $s->external_id, 'id' => null, 'status' => $s->status,
                'sent_at' => null, 'viewed_at' => null, 'signed_at' => null, 'declined_at' => null, 'otp_verified_at' => null,
            ])->all(),
            'completed_at' => null,
        ];
    }

    public function documents(string $submissionId): array
    {
        return [];
    }

    public function download(string $url): string
    {
        throw new SigningFailed(__('Simulation: there is nothing to download.'));
    }

    public function verifyWebhook(string $rawBody, ?string $signature): bool
    {
        return false;
    }
}
