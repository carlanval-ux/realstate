<?php

declare(strict_types=1);

namespace App\Signing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A signer of a request: role, identity, provider ids and state. The signing link is stored encrypted. */
final class SignatureSigner extends Model
{
    public const STATUSES = ['pendiente' => 'Pending', 'enviado' => 'Sent', 'visto' => 'Viewed', 'firmado' => 'Signed', 'rechazado' => 'Declined'];

    /** Order of states: a late webhook never moves a signer backwards. */
    public const RANK = ['pendiente' => 0, 'enviado' => 1, 'visto' => 2, 'firmado' => 3, 'rechazado' => 3];

    protected $table = 'clarto_signature_signers';

    protected $guarded = ['id'];

    protected $hidden = ['sign_url'];

    protected function casts(): array
    {
        return [
            'sign_url' => 'encrypted', 'require_phone_2fa' => 'boolean',
            'sent_at' => 'datetime', 'viewed_at' => 'datetime', 'signed_at' => 'datetime', 'declined_at' => 'datetime', 'otp_verified_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(SignatureRequest::class, 'signature_request_id');
    }
}
