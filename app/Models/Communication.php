<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One communication with a client, whatever the channel (WhatsApp, SMS, e-mail) and whoever sent it (the team from
 * the panel, rentals, signatures, a system e-mail) or received it. The single log behind every client timeline.
 */
final class Communication extends Model
{
    public const IN = 'in';

    public const OUT = 'out';

    /** Received from a phone that is not (or not unambiguously) a contact: waits in the resolution queue. */
    public const UNLINKED = 'sin_vincular';

    /** channel => [label, heroicon, colour] */
    public const CHANNELS = [
        'whatsapp' => ['WhatsApp', 'heroicon-o-chat-bubble-left-right', '#25d366'],
        'sms' => ['SMS', 'heroicon-o-device-phone-mobile', '#3b82f6'],
        'email' => ['E-mail', 'heroicon-o-envelope', '#f59e0b'],
    ];

    /** Why the client is contacted. Marketing only with the client's consent; everything else is service. */
    public const PURPOSES = ['servicio', 'cobro', 'incidencia', 'contrato', 'viajeros', 'liquidacion', 'firma', 'marketing'];

    /** purpose => label (translated with __()) */
    public const PURPOSE_LABELS = [
        'servicio' => 'Service', 'cobro' => 'Payment', 'incidencia' => 'Incident', 'contrato' => 'Contract', 'viajeros' => 'Travellers',
        'liquidacion' => 'Settlement', 'firma' => 'Signature', 'marketing' => 'Marketing (needs consent)',
    ];

    public const STATUSES = [
        'pendiente' => 'Sending…', 'enviado' => 'Sent', 'entregado' => 'Delivered', 'leido' => 'Read', 'error' => 'Not sent',
        'recibido' => 'Received', self::UNLINKED => 'Pending link', 'descartado' => 'Dismissed',
    ];

    protected $table = 'clarto_communications';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['candidates' => 'array', 'sent_at' => 'datetime', 'read_at' => 'datetime'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function statusLabel(): string
    {
        return __(self::STATUSES[$this->status] ?? $this->status);
    }

    public function channelLabel(): string
    {
        return __(self::CHANNELS[$this->channel][0] ?? $this->channel);
    }

    /** Who/what the message went to or came from: number or address. */
    public function address(): ?string
    {
        return $this->channel === 'email' ? $this->email : $this->phone;
    }
}
