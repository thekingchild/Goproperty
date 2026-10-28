<?php

declare(strict_types=1);

namespace App\Providers;

use App\Documents\Verification\DocumentVerifiers;
use App\Exports\ListExports;
use App\Media\MediaMover;
use App\Members\InvitationList;
use App\Members\MemberList;
use App\Navigation\PortalTabs;
use App\Navigation\SettingsTabs;
use App\Navigation\WorkspaceNavigation;
use App\Settings\SettingDefinition;
use App\Settings\SettingsRegistry;
use App\Tenancy\Moves\BuildingRecordMovers;
use App\Tenancy\OrganizationContext;
use App\Tenancy\PortalLanding;
use App\Theme\ThemeContext;
use App\WhatsApp\LogWhatsAppSender;
use App\WhatsApp\NoWhatsAppSender;
use App\WhatsApp\WhatsAppSender;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Fresh for every request and queued job (ARCHITECTURE.md § 4).
        $this->app->scoped(OrganizationContext::class);

        // The published theme unless the console preview switches to the draft.
        $this->app->scoped(ThemeContext::class);

        // The workspace sidebar: Core's pages, then whatever modules add
        // from their providers (callAfterResolving).
        $this->app->singleton(WorkspaceNavigation::class, static fn (): WorkspaceNavigation => WorkspaceNavigation::withCoreItems());
        $this->app->singleton(SettingsTabs::class, static fn (): SettingsTabs => SettingsTabs::withCoreTabs());
        $this->app->singleton(PortalTabs::class);

        // Where someone with no workspace lands: modules register their
        // portals from their providers (callAfterResolving), like the sidebar.
        $this->app->singleton(PortalLanding::class);

        // Verification resolvers of issued documents: modules register theirs
        // from their providers (callAfterResolving), like the sidebar.
        $this->app->singleton(DocumentVerifiers::class);

        // The lists that export (P02-07): Core's, then whatever modules add
        // from their providers (callAfterResolving).
        $this->app->singleton(ListExports::class, static function (Application $app): ListExports {
            $exports = new ListExports($app);
            $exports->register(MemberList::class);
            $exports->register(InvitationList::class);

            return $exports;
        });

        // What a management handover moves (ARCHITECTURE.md § 6.1): the media
        // library's files here, every module's live records from its provider
        // (callAfterResolving), like the sidebar.
        $this->app->singleton(BuildingRecordMovers::class, static function (Application $app): BuildingRecordMovers {
            $movers = new BuildingRecordMovers($app);
            $movers->register(MediaMover::class);

            return $movers;
        });

        // The organization's own WhatsApp (ARCHITECTURE.md § 6.7): the log
        // stand-in until P05-06 adds the Cloud API driver (P03 decision 3).
        $this->app->singleton(WhatsAppSender::class, static fn (): WhatsAppSender => match (config('services.whatsapp.driver')) {
            'log' => new LogWhatsAppSender,
            default => new NoWhatsAppSender,
        });

        $this->app->singleton(SettingsRegistry::class, function (): SettingsRegistry {
            $registry = new SettingsRegistry;
            $definitions = config('settings.definitions', []);

            foreach (is_array($definitions) ? $definitions : [] as $key => $definition) {
                if (is_string($key) && is_array($definition)) {
                    $registry->define(SettingDefinition::fromArray($key, $definition));
                }
            }

            return $registry;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        // New customer passwords (UX_DESIGN.md § 3.1): at least 10
        // characters, and in production not on a breached-password list.
        Password::defaults(fn (): Password => app()->isProduction()
            ? Password::min(10)->uncompromised()
            : Password::min(10),
        );
    }
}
