<?php

declare(strict_types=1);

namespace App\Acquisition;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One planned nurture WhatsApp (wa1…wa4, wa_t1, wa_t2, wa_f1): programado → enviado | cancelado | omitido. */
final class NurtureSlot extends Model
{
    protected $table = 'clarto_whatsapp_nurture_slots';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['send_after' => 'datetime', 'window_end' => 'datetime', 'sent_at' => 'datetime'];
    }

    /** @return BelongsTo<NurturePlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(NurturePlan::class, 'plan_id');
    }
}
