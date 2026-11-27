<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Signing\DocusealCheck;
use Illuminate\Console\Command;

/**
 * Read-only check of the DocuSeal setup on this server (key, webhook secret, templates of the active forms).
 * Used by pwb scripts/set-docuseal.sh; prints one «OK|AVISO|ERROR text» line each and fails on any ERROR.
 *
 *   php artisan clarto:firma-comprobar
 */
final class CheckDocuseal extends Command
{
    protected $signature = 'clarto:firma-comprobar';

    protected $description = 'Check the DocuSeal key, webhook secret and templates on this server (read only)';

    public function handle(): int
    {
        $check = new DocusealCheck;
        foreach ($check->run() as [$level, $text]) {
            $this->line($level.' '.$text);
        }

        return $check->failed() ? self::FAILURE : self::SUCCESS;
    }
}
