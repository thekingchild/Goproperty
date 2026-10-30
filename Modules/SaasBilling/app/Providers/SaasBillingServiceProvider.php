<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Providers;

use Modules\SaasBilling\Contracts\SmsCreditCheckout;
use Modules\SaasBilling\Contracts\SubscriptionQuotes;
use Modules\SaasBilling\Quotes\MonthlyBills;
use Modules\SaasBilling\SmsCredits\SmsCreditPurchases;
use Nwidart\Modules\Support\ModuleServiceProvider;

/**
 * The SaasBilling module (ARCHITECTURE.md § 6.1, § 8; BILLING_PAYMENTS.md):
 * GoProperty's own subscription billing. Built so far: the monthly bill
 * quote (Contracts\SubscriptionQuotes) that the management handover's
 * accept screen shows (P03-14). P02-04's billing transfers were reworked
 * into the management handover (Modules\Handovers, P03-14); their old
 * pages answer with a redirect there (routes/web.php). SMS credit bundles
 * (P05-03): Contracts\SmsCreditCheckout for Core's Settings → SMS credits
 * page, the first platform invoices and payments, GoProperty's own
 * Paystack checkout, its return and its webhook (/webhooks/paystack).
 * Subscriptions and their invoices come in P06.
 */
final class SaasBillingServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'SaasBilling';

    protected string $nameLower = 'saasbilling';

    /** @var list<class-string> */
    protected array $providers = [
        RouteServiceProvider::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->app->bind(SubscriptionQuotes::class, MonthlyBills::class);
        $this->app->bind(SmsCreditCheckout::class, SmsCreditPurchases::class);
    }
}
