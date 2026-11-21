<?php

declare(strict_types=1);

namespace App\Signing;

/**
 * What CLARTO needs from an e-signature provider. Today: DocuSeal (DocusealProvider) or a simulation. The shared
 * «Firma API» planned by Red Telecom can replace DocuSeal later with a third implementation and no other change
 * (decision documented in docs/clarto/formularios-firma.md).
 */
interface SignatureProvider
{
    /** docuseal | simulado | … (stored on each request) */
    public function name(): string;

    /** sandbox | production (stored on each request; webhooks are matched against it) */
    public function environment(): ?string;

    /** Whether this provider/environment can deliver a signature level (simple | avanzada). */
    public function supportsLevel(string $level): bool;

    /**
     * Creates the signing request. Idempotent on the signers' external_id: a retry after an uncertain failure
     * returns the submission already created.
     *
     * @param  string|null  $pdf  PDF bytes when the document is a PDF (template source "pdf" or an attached PDF); null for a provider template
     * @return array{submission_id: string, submitters: list<array{external_id: string, id: ?string, url: ?string}>}
     */
    public function create(SignatureRequest $request, ?string $pdf): array;

    /**
     * Authoritative state of a submission (webhooks are only notices).
     *
     * @return array{status: string, submitters: list<array{external_id: ?string, id: ?string, status: string, sent_at: ?string, viewed_at: ?string, signed_at: ?string, declined_at: ?string, otp_verified_at: ?string}>, completed_at: ?string}
     */
    public function fetch(string $submissionId): array;

    /**
     * Signed documents and audit log of a completed submission (temporary URLs: never stored).
     *
     * @return list<array{kind: string, name: string, url: string}>
     */
    public function documents(string $submissionId): array;

    public function download(string $url): string;

    /** Checks the webhook signature over the raw body. */
    public function verifyWebhook(string $rawBody, ?string $signature): bool;
}
