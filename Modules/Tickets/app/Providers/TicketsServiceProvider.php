<?php

declare(strict_types=1);

namespace Modules\Tickets\Providers;

use App\Exports\ListExports;
use App\Members\MemberRemoved;
use App\Models\Organization;
use App\Models\User;
use App\Navigation\PortalTab;
use App\Navigation\PortalTabs;
use App\Navigation\SettingsTab;
use App\Navigation\SettingsTabs;
use App\Navigation\WorkspaceNavigation;
use App\Navigation\WorkspaceNavItem;
use App\Settings\SettingDefinition;
use App\Settings\SettingScope;
use App\Settings\SettingsRegistry;
use App\Settings\SettingType;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\Tickets\Contracts\TicketService as TicketServiceContract;
use Modules\Tickets\Jobs\CloseResolvedTickets;
use Modules\Tickets\Listeners\UnassignRemovedMember;
use Modules\Tickets\Lists\TicketList;
use Modules\Tickets\Models\Ticket;
use Modules\Tickets\Models\TicketMessage;
use Modules\Tickets\Policies\TicketPolicy;
use Modules\Tickets\Tickets\TicketService;
use Modules\Tickets\Tickets\TicketSettings;
use Nwidart\Modules\Support\ModuleServiceProvider;

/**
 * The Tickets module (ARCHITECTURE.md § 6.6, § 8; P04-01): maintenance
 * tickets. The base provider loads the module's views (`tickets::`) and
 * migrations; this one binds Contracts\TicketService (what other modules
 * may read) to Tickets\TicketService (the only writer), and adds
 * TicketPolicy, the workspace pages and the tenant portal's pages (routes,
 * Livewire namespace `tickets::`, classes in Modules\Tickets\Livewire),
 * the sidebar item "Tickets", the portal tab "Repairs", the list's export
 * (Lists\TicketList), the organization settings `tickets.reopen_days`
 * (default 14, 1–90) and `tickets.auto_close_days` (default 7, 1–30) with
 * their page under Settings, the daily `tickets-auto-close` job
 * (Schedule::job, ARCHITECTURE.md § 7) and, when a member leaves, the
 * unassignment of the tickets waiting on them (App\Members\MemberRemoved).
 *
 * Events for other modules and later tasks: Events\TicketStatusChanged
 * (every move, after the commit) and Events\TicketAssignmentChanged.
 *
 * P04-02 adds the thread's media collection `ticket_attachments`
 * (evidence) and, in routes/web.php, the files' routes and the owner
 * portal's tickets (`/owner/tickets`); Events\TicketMessagePosted is sent
 * for every message (Messages\TicketMessages).
 */
final class TicketsServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Tickets';

    protected string $nameLower = 'tickets';

    /** @var list<class-string> */
    protected array $providers = [
        RouteServiceProvider::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->app->bind(TicketServiceContract::class, TicketService::class);

        // Ticket attachments (P04-02): photos (iPhones' HEIC too) and PDFs
        // of the problem, 10 MB each; evidence (never deleted; ARCHITECTURE.md § 9).
        if (! is_array(config('media.collections.'.TicketMessage::COLLECTION))) {
            config(['media.collections.'.TicketMessage::COLLECTION => [
                'mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif', 'application/pdf'],
                'max_kb' => 10 * 1024,
                'conversions' => ['thumb'],
                'kind' => 'evidence',
            ]]);
        }
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(Ticket::class, TicketPolicy::class);

        Livewire::addNamespace($this->nameLower, classNamespace: 'Modules\\Tickets\\Livewire');

        $this->callAfterResolving(SettingsRegistry::class, static function (SettingsRegistry $settings): void {
            $settings->define(new SettingDefinition(TicketSettings::REOPEN_DAYS, SettingType::Integer, TicketSettings::DEFAULT_REOPEN_DAYS, ['integer', 'between:1,'.TicketSettings::MAX_REOPEN_DAYS], SettingScope::Organization));
            $settings->define(new SettingDefinition(TicketSettings::AUTO_CLOSE_DAYS, SettingType::Integer, TicketSettings::DEFAULT_AUTO_CLOSE_DAYS, ['integer', 'between:1,'.TicketSettings::MAX_AUTO_CLOSE_DAYS], SettingScope::Organization));
        });

        $this->callAfterResolving(SettingsTabs::class, static function (SettingsTabs $tabs): void {
            $tabs->add(new SettingsTab(
                key: 'tickets',
                label: 'Maintenance tickets',
                route: 'workspace.settings.operations.tickets',
                visible: static fn (User $user, Organization $organization): bool => $user->can('viewSettings', $organization),
                order: 60,
            ));
        });

        // Every list of the module exports (P02-07).
        $this->callAfterResolving(ListExports::class, static function (ListExports $exports): void {
            $exports->register(TicketList::class);
        });

        $this->callAfterResolving(WorkspaceNavigation::class, static function (WorkspaceNavigation $navigation): void {
            $navigation->add(new WorkspaceNavItem(
                key: 'tickets',
                label: 'Tickets',
                icon: 'wrench-screwdriver',
                route: 'workspace.tickets.index',
                active: ['workspace.tickets.*'],
                visible: static fn (User $user): bool => $user->can('viewAny', Ticket::class),
                order: 495,
            ));
        });

        $this->callAfterResolving(PortalTabs::class, static function (PortalTabs $tabs): void {
            $tabs->add(new PortalTab(key: 'repairs', label: 'Repairs', icon: 'wrench-screwdriver', route: 'portal.tickets.index', active: ['portal.tickets.*'], order: 25));
        });

        // A member who leaves stops holding tickets (inside the removal's transaction).
        Event::listen(MemberRemoved::class, UnassignRemovedMember::class);

        // Resolved tickets nobody answered close themselves (ARCHITECTURE.md § 7).
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->job(new CloseResolvedTickets, 'default')->name('tickets-auto-close')->dailyAt('04:30');
        });
    }
}
