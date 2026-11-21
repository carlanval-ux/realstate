<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One WhatsApp message of the company line, sent from the panel ("out") or received ("in"). */
final class WhatsAppMessage extends Model
{
    public const IN = 'in';

    public const OUT = 'out';

    /** Received from a phone that is not (or not unambiguously) a contact: waits in the resolution queue. */
    public const UNLINKED = 'sin_vincular';

    public const STATUSES = [
        'pendiente' => 'Sending…', 'enviado' => 'Sent', 'entregado' => 'Delivered', 'leido' => 'Read', 'error' => 'Not sent',
        'recibido' => 'Received', self::UNLINKED => 'Pending link', 'descartado' => 'Dismissed',
    ];

    protected $table = 'clarto_whatsapp_messages';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['candidates' => 'array', 'sent_at' => 'datetime'];
    }

    public function statusLabel(): string
    {
        return __(self::STATUSES[$this->status] ?? $this->status);
    }
}
