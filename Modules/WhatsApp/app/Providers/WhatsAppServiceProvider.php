<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Providers;

use App\Models\Organization;
use App\Models\User;
use App\Navigation\SettingsTab;
use App\Navigation\SettingsTabs;
use App\Notifications\Channels\WhatsAppRouting;
use App\Notifications\Preferences\NotificationSettingsPanels;
use App\WhatsApp\WhatsAppSender;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\Tenants\Contracts\TenantPagePanels;
use Modules\WhatsApp\Jobs\SyncWhatsAppTemplates;
use Modules\WhatsApp\Meta\CloudApi;
use Modules\WhatsApp\Meta\HttpCloudApi;
use Modules\WhatsApp\Meta\LogCloudApi;
use Modules\WhatsApp\Models\WhatsAppConnection;
use Modules\WhatsApp\Policies\WhatsAppPolicy;
use Modules\WhatsApp\Sending\CloudWhatsAppSender;
use Modules\WhatsApp\Sending\WhatsAppRoutes;
use Nwidart\Modules\Support\ModuleServiceProvider;

/**
 * The WhatsApp module (ARCHITECTURE.md § 6.7, § 8; P05-06): messages from
 * the organization's OWN WhatsApp Business account through Meta's Cloud
 * API (Growth plan and above; Meta bills the agency). This provider:
 *
 * - puts the `cloud` driver behind Core's App\WhatsApp\WhatsAppSender when
 *   `services.whatsapp.driver` is `cloud` (the `log` and `none` drivers
 *   stay Core's), and binds Core's WhatsAppRouting for reminders;
 * - binds Meta's API: HttpCloudApi only in production with the `cloud`
 *   driver, the LogCloudApi stand-in everywhere else (AGENT.md § 7);
 * - registers WhatsAppPolicy, the Livewire namespace `whatsapp::`, the
 *   Settings tab "WhatsApp" (owners and administrators), the tenant
 *   page's consent panel (Tenants' TenantPagePanels), the tenant's own
 *   panel on Settings → Notifications, the daily template sync and the
 *   routes (settings pages; the webhook /webhooks/whatsapp/{ulid}).
 */
final class WhatsAppServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'WhatsApp';

    protected string $nameLower = 'whatsapp';

    /** @var list<class-string> */
    protected array $providers = [
        RouteServiceProvider::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->app->singleton(CloudApi::class, static function (Application $app): CloudApi {
            if (config('services.whatsapp.driver') === 'cloud' && in_array($app->environment(), ['production'], true)) {
                return new HttpCloudApi(
                    (string) config('services.whatsapp.graph_url', 'https://graph.facebook.com'),
                    (string) config('services.whatsapp.graph_version', 'v21.0'),
                );
            }

            return new LogCloudApi;
        });

        $this->app->extend(WhatsAppSender::class, static fn (WhatsAppSender $sender, Application $app): WhatsAppSender => config('services.whatsapp.driver') === 'cloud'
            ? $app->make(CloudWhatsAppSender::class)
            : $sender);

        // A new one for each class that asks (the planner, the sender, …): its answers last one dispatch run at most.
        $this->app->bind(WhatsAppRouting::class, WhatsAppRoutes::class);
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(WhatsAppConnection::class, WhatsAppPolicy::class);

        Livewire::addNamespace($this->nameLower, classNamespace: 'Modules\\WhatsApp\\Livewire');

        $this->callAfterResolving(SettingsTabs::class, static function (SettingsTabs $tabs): void {
            $tabs->add(new SettingsTab(
                key: 'whatsapp',
                label: 'WhatsApp',
                route: 'workspace.settings.operations.whatsapp',
                visible: static fn (User $user, Organization $organization): bool => $user->can('viewAny', WhatsAppConnection::class),
                order: 60,
            ));
        });

        $this->callAfterResolving(TenantPagePanels::class, static fn (TenantPagePanels $panels) => $panels->register('whatsapp::tenant-opt-in'));
        $this->callAfterResolving(NotificationSettingsPanels::class, static fn (NotificationSettingsPanels $panels) => $panels->register('whatsapp::portal-opt-ins'));

        // Templates fetched from Meta every day at 05:20 Lagos (04:20 UTC; ARCHITECTURE.md § 6.7, § 7), before the 08:00 reminders.
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->job(new SyncWhatsAppTemplates, 'notifications')->name('whatsapp-templates-sync')->dailyAt('04:20');
        });
    }
}
