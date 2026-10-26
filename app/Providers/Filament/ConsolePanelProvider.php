<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Http\Controllers\Console\ThemePreviewController;
use App\Platform\Console\ConsoleRequests;
use App\Theme\ThemeContext;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The platform console (PLATFORM_CONSOLE.md): a Filament panel at
 * /{ADMIN_PATH} (or on ADMIN_DOMAIN), signed in on the `staff` guard with its
 * own session cookie (App\Http\Middleware\UseConsoleSession). Console code
 * that reads across organizations lives in App\Platform, the only namespace
 * allowed to (ARCHITECTURE.md § 4). With an invalid ADMIN_PATH the console
 * is not registered at all, rather than served at a guessable address.
 */
final class ConsolePanelProvider extends PanelProvider
{
    public function register(): void
    {
        if ($this->app->make(ConsoleRequests::class)->enabled()) {
            parent::register();

            return;
        }

        $this->app->booted(fn () => Log::critical('The platform console is off: ADMIN_PATH breaks the rules in PLATFORM_CONSOLE.md § 10.'));
    }

    public function panel(Panel $panel): Panel
    {
        $console = $this->app->make(ConsoleRequests::class);
        $domain = $console->domain();

        $panel = $panel
            ->id('console')
            ->default()
            ->path((string) $console->path())
            ->login()
            ->authGuard('staff')
            // The console follows the published platform theme (PLATFORM_CONSOLE.md § 9.7).
            ->brandName(fn (): string => app(ThemeContext::class)->current()->name().' console')
            ->colors(fn (): array => ['primary' => app(ThemeContext::class)->current()->primaryPalette()])
            ->font(fn (): string => app(ThemeContext::class)->current()->fontFamily(), provider: LocalFontProvider::class)
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): string => Blade::render('<x-theme-styles />'))
            ->authenticatedRoutes(function (): void {
                // Draft preview (§ 9.8): staff only, never cached, never indexed.
                Route::get('theme-preview/{surface}', ThemePreviewController::class)
                    ->whereIn('surface', ThemePreviewController::SURFACES)
                    ->name('theme-preview');
            })
            ->discoverResources(in: app_path('Platform/Filament/Resources'), for: 'App\\Platform\\Filament\\Resources')
            ->discoverPages(in: app_path('Platform/Filament/Pages'), for: 'App\\Platform\\Filament\\Pages')
            ->pages([Dashboard::class])
            ->widgets([])
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
            ])
            ->authMiddleware([Authenticate::class]);

        return $domain !== null ? $panel->domain($domain) : $panel;
    }
}
