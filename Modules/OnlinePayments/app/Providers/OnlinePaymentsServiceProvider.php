<?php

declare(strict_types=1);

namespace Modules\OnlinePayments\Providers;

use App\Models\Organization;
use App\Models\User;
use App\Navigation\SettingsTab;
use App\Navigation\SettingsTabs;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\Billing\Contracts\PaymentOptions;
use Modules\OnlinePayments\Models\PaystackConnection;
use Modules\OnlinePayments\PayOnline\PaystackOption;
use Modules\OnlinePayments\Policies\PaystackConnectionPolicy;
use Nwidart\Modules\Support\ModuleServiceProvider;

/**
 * The OnlinePayments module (ONBOARDING_COMPLIANCE.md § 4.2, ARCHITECTURE.md
 * § 6.4, § 8, § 10): tenants pay bills online through the organization's
 * OWN Paystack account; GoProperty never receives the money (AGENT.md § 3
 * rule 8). The base provider loads the module's views (`onlinepayments::`)
 * and migrations; this one adds PaystackConnectionPolicy, the settings page
 * (Livewire namespace `onlinepayments::`, tab "Online payments" under
 * Settings, org-owners only), the "Pay online" option on Billing's pay page
 * and invoices (Billing's PaymentOptions slot), and the routes: the
 * per-organization webhook /webhooks/paystack/{webhook_token} (outside the
 * `web` group) and the pay-online pages.
 */
final class OnlinePaymentsServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'OnlinePayments';

    protected string $nameLower = 'onlinepayments';

    /** @var list<class-string> */
    protected array $providers = [
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();

        Gate::policy(PaystackConnection::class, PaystackConnectionPolicy::class);

        Livewire::addNamespace($this->nameLower, classNamespace: 'Modules\\OnlinePayments\\Livewire');

        $this->callAfterResolving(PaymentOptions::class, static function (PaymentOptions $options): void {
            $options->register(PaystackOption::class);
        });

        $this->callAfterResolving(SettingsTabs::class, static function (SettingsTabs $tabs): void {
            $tabs->add(new SettingsTab(
                key: 'paystack',
                label: 'Online payments',
                route: 'workspace.settings.payments.paystack',
                visible: static fn (User $user, Organization $organization): bool => $user->can('viewAny', PaystackConnection::class),
                order: 25,
            ));
        });
    }
}
