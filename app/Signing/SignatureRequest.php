<?php

declare(strict_types=1);

namespace App\Signing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One document sent for signature, with the exact version sent (template snapshot, values, PDF hash). */
final class SignatureRequest extends Model
{
    public const STATUSES = [
        'borrador' => ['Draft', 'gray'],
        'enviada' => ['Sent', 'info'],
        'en_curso' => ['In progress', 'warning'],
        'completada' => ['Signed by everyone', 'success'],
        'rechazada' => ['Declined', 'danger'],
        'caducada' => ['Expired', 'gray'],
        'anulada' => ['Cancelled', 'gray'],
        'error' => ['Error', 'danger'],
    ];

    /** States that never go back (a late or out-of-order webhook cannot reopen them). */
    public const FINAL = ['completada', 'rechazada', 'caducada', 'anulada'];

    protected $table = 'clarto_signature_requests';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['template_snapshot' => 'array', 'values' => 'array', 'sent_at' => 'datetime', 'completed_at' => 'datetime', 'reconciled_at' => 'datetime'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(FormTemplate::class, 'form_template_id');
    }

    public function signers(): HasMany
    {
        return $this->hasMany(SignatureSigner::class)->orderBy('position');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(SignatureDocument::class);
    }

    public function smsMessages(): HasMany
    {
        return $this->hasMany(SmsMessage::class)->latest('id');
    }

    public function isFinal(): bool
    {
        return in_array($this->status, self::FINAL, true);
    }

    public function statusLabel(): string
    {
        return __(self::STATUSES[$this->status][0] ?? $this->status);
    }
}
