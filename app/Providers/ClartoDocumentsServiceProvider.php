<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Documents on the records (docs/clarto/documentos.md): property «Photos and videos» and «Documents» tabs, client
 * «Documents» (own files, generated files, linked properties). The module's global «Documents and photos» list is
 * no longer a working screen: it leaves the menu and only super administrators may open it («Archivo general»).
 */
final class ClartoDocumentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Livewire's temporary upload rule (12 MB by default) would refuse videos before the field's own limit.
        config(['livewire.temporary_file_upload.rules' => ['required', 'file', 'max:'.\App\Filament\PropertyPhotos::VIDEO_MAX_KB]]);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/clarto-documentos.php');
        \Livewire\Livewire::component('clarto-record-files', \App\Filament\Documents\RecordFiles::class);
        // A new file is an operation of the «Documents and forms» module (existing files stay readable when it is off).
        \App\Documents\RecordDocument::creating(fn () => \App\Modules\ClartoModules::ensureEnabled(\App\Modules\ClartoModules::DOCUMENTOS));

        // «Archivo general»: every MediaDocument ability is for super administrators only (Shield grants them first).
        Gate::before(function (?object $user, string $ability): ?bool {
            if (! str_ends_with($ability, ':MediaDocument')) {
                return null;
            }

            return $user !== null && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin() ? null : false;
        });

        if ($this->app->runningInConsole()) {
            $this->commands([\App\Console\Commands\TestDocuments::class]);
        }
    }
}
