<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\InboundMail\InboundMails;
use Illuminate\Console\Command;

/** Reads new messages of the company mailbox (read-only) and triages them. Scheduled every minute; it honours the interval set in the panel. */
final class PollInboundMail extends Command
{
    protected $signature = 'clarto:correo-sondear {--ahora : ignore the interval}';

    protected $description = 'Read new messages of the company mailbox (read-only) and route them';

    public function handle(): int
    {
        $r = InboundMails::poll(force: (bool) $this->option('ahora'));
        $this->line($r['estado'].(isset($r['nuevos']) ? ' · nuevos: '.$r['nuevos'] : '').(isset($r['error']) ? ' · '.$r['error'] : ''));

        return $r['estado'] === 'error' ? self::FAILURE : self::SUCCESS;
    }
}
