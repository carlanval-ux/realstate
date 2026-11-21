<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Acquisition\NurturePlan;
use Illuminate\Console\Command;

/** Sends the due nurture WhatsApp (one per run, scheduled every minute in the 17:30–18:30 window) and skips missed ones. */
final class SendWhatsAppNurture extends Command
{
    protected $signature = 'clarto:nutricion-whatsapp';

    protected $description = 'Send due nurture WhatsApp messages (B.3 v2) after checking every stop condition';

    public function handle(): int
    {
        $this->line(json_encode(NurturePlan::runDue(), JSON_UNESCAPED_UNICODE) ?: '');

        return self::SUCCESS;
    }
}
