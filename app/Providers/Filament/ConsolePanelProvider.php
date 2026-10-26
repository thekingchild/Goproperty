<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Http\Controllers\Console\SchedulerHeartbeatController;
use App\Http\Controllers\Console\ThemePreviewController;
use App\Http\Middleware\StaffAccountState;
use App\Http\Middleware\StaffIdleTimeout;
use App\Http\Middleware\StaffIpAllowlist;
use App\Platform\Console\ConsoleRequests;
use App\Platform\Filament\Auth\AcceptStaffInvitation;
use App\Platform\Filament\Auth\StaffLogin;
use App\Platform\Staff\SuperAdminFloor;
use App\Theme\BrandAssets;
use App\Theme\ThemeContext;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\Http\Middleware\EnsureMultiFactorAuthenticationIsEnabled;
use Filament\Auth\MultiFactor\Pages\SetUpRequiredMultiFactorAuthentication;
use Filament\Facades\Filament;
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
            ->login(StaffLogin::class)
            ->authGuard('staff')
            // Staff must use an authenticator app (UX_DESIGN.md § 3.2): without
            // one they are sent to set it up before any console page opens.
            // The authenticator entry is named after the panel brand, read lazily.
            ->multiFactorAuthentication([AppAuthentication::make()->recoverable()], isRequired: true)
            // The console follows the published platform theme (PLATFORM_CONSOLE.md § 9.7).
            ->brandName(fn (): string => app(ThemeContext::class)->current()->name().' console')
            ->brandLogo(fn (): ?string => app(BrandAssets::class)->url(app(ThemeContext::class)->current(), 'logo-light'))
            ->darkModeBrandLogo(fn (): ?string => app(BrandAssets::class)->url(app(ThemeContext::class)->current(), 'logo-dark'))
            ->brandLogoHeight('2rem')
            ->favicon(fn (): string => app(BrandAssets::class)->faviconUrl(app(ThemeContext::class)->current()))
            ->colors(fn (): array => ['primary' => app(ThemeContext::class)->current()->primaryPalette()])
            ->font(fn (): string => app(ThemeContext::class)->current()->fontFamily(), provider: LocalFontProvider::class)
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): string => Blade::render('<x-theme-styles />'))
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): string => view('console.auth-style')->render(), scopes: [StaffLogin::class, SetUpRequiredMultiFactorAuthentication::class, AcceptStaffInvitation::class])
            ->renderHook(PanelsRenderHook::BODY_END, fn (): string => Filament::auth()->check() ? view('console.idle-warning')->render() : '')
            // Until two active super-admins exist (PLATFORM_CONSOLE.md § 1).
            ->renderHook(PanelsRenderHook::CONTENT_START, fn (): string => Filament::auth()->check() && app(SuperAdminFloor::class)->belowRequired()
                ? view('console.super-admin-warning', ['count' => app(SuperAdminFloor::class)->activeCount()])->render()
                : '')
            // Invitation link (PLATFORM_CONSOLE.md § 1): set a password, then 2FA.
            ->routes(function (): void {
                Route::get('invitation/{token}', AcceptStaffInvitation::class)
                    ->middleware('throttle:30,1')
                    ->name('auth.staff-invitation');
            })
            ->authenticatedRoutes(function (): void {
                // Filament adds the 2FA-required check to its own pages only;
                // these routes need it too (UX_DESIGN.md § 3.2).
                Route::middleware(EnsureMultiFactorAuthenticationIsEnabled::class)->group(function (): void {
                    // Draft preview (§ 9.8): staff only, never cached, never indexed.
                    Route::get('theme-preview/{surface}', ThemePreviewController::class)
                        ->whereIn('surface', ThemePreviewController::SURFACES)
                        ->name('theme-preview');

                    // "Stay signed in" on the idle warning: the request itself is the activity.
                    Route::get('session/ping', fn () => response()->noContent())->name('session-ping');

                    // Scheduler and queue-tick heartbeats, polled by the installer's finish screen.
                    Route::get('system/heartbeat', SchedulerHeartbeatController::class)->name('system-heartbeat');
                });
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
            // Also on Livewire updates from console pages: deactivated
            // staff are signed out at once, and the 2FA requirement holds
            // for every request, not just page loads.
            ->middleware([StaffAccountState::class], isPersistent: true)
            ->persistentMiddleware([EnsureMultiFactorAuthenticationIsEnabled::class])
            ->authMiddleware([Authenticate::class, StaffIpAllowlist::class, StaffIdleTimeout::class]);

        return $domain !== null ? $panel->domain($domain) : $panel;
    }
}
