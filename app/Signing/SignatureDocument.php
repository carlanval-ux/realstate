<?php

declare(strict_types=1);

namespace App\Signing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Signed PDF or audit log of a request, stored on the private disk with its SHA-256. */
final class SignatureDocument extends Model
{
    public const KINDS = ['firmado' => 'Signed document', 'auditoria' => 'Audit log / evidence'];

    protected $table = 'clarto_signature_documents';

    protected $guarded = ['id'];

    public function request(): BelongsTo
    {
        return $this->belongsTo(SignatureRequest::class, 'signature_request_id');
    }
}
