<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/** Enable or disable a user's access (password and Google alike): php artisan clarto:usuario email --desactivar */
final class UserAccess extends Command
{
    protected $signature = 'clarto:usuario {email} {--desactivar} {--activar}';

    protected $description = 'Disable or enable a panel user (no data is deleted)';

    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();
        if ($user === null) {
            $this->error('No existe ese usuario.');

            return self::FAILURE;
        }

        if ($this->option('desactivar') || $this->option('activar')) {
            $user->forceFill(['disabled_at' => $this->option('desactivar') ? now() : null])->save();
        }

        $google = $user->connectedAccounts()->where('provider', 'google')->exists() ? 'Google vinculado' : 'sin Google';
        $this->line(sprintf('%s: %s · %s', $user->email, $user->disabled_at ? 'DESACTIVADO desde '.$user->disabled_at : 'activo', $google));

        return self::SUCCESS;
    }
}
