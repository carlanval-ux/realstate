<?php

declare(strict_types=1);

namespace App\Providers;

use App\Collaborators\CollaboratorAccess;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Liberu\Foundation\ApplicationCore\Http\Middleware\SecurityHeaders;
use Liberu\Foundation\Localization\Http\Middleware\SetLocale;

/**
 * Panel de colaboradores (/colaboradores): sin equipos ni tenant, solo para colaboradores habilitados
 * (App\Models\User::canAccessPanel). Cada pantalla consulta a través de CollaboratorAccess. Otros módulos
 * («Solicitudes») añaden recursos y páginas en app/Filament/Colaboradores/{Resources,Pages}.
 */
final class CollaboratorPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id(CollaboratorAccess::PANEL)
            ->path(CollaboratorAccess::PANEL)
            ->login()
            ->passwordReset()
            ->viteTheme('resources/css/filament/theme.css')
            ->brandName('CLARTO · Colaboradores')
            ->favicon(asset('favicon.svg'))
            ->brandLogo(asset('brand/clarto-logo-color.png'))
            ->darkModeBrandLogo(asset('brand/clarto-logo-negativo.png'))
            ->brandLogoHeight('2.25rem')
            ->font('DM Sans')
            ->colors(['primary' => Color::hex('#173D38'), 'warning' => Color::hex('#BD7968')])
            ->renderHook(
                PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE,
                fn (): string => Blade::render('<p style="margin-top:-.5rem;text-align:center;letter-spacing:.18em;text-transform:uppercase;font-size:.72rem;font-weight:600;color:#9E5847">{{ $t }}</p>', ['t' => __('colaboradores.menu')]),
            )
            ->globalSearch()
            ->sidebarCollapsibleOnDesktop()
            ->discoverResources(in: app_path('Filament/Colaboradores/Resources'), for: 'App\Filament\Colaboradores\Resources')
            ->discoverPages(in: app_path('Filament/Colaboradores/Pages'), for: 'App\Filament\Colaboradores\Pages')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                SetLocale::class,
                SecurityHeaders::class,
            ])
            ->authMiddleware([Authenticate::class]);
    }
}
