<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Providers;

use App\Mail\Templates\TemplateCatalog;
use App\Subscriptions\Events\SubscriptionStatusChanged;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Modules\SaasBilling\Contracts\PlatformWebhookReplays;
use Modules\SaasBilling\Contracts\SmsCreditCheckout;
use Modules\SaasBilling\Contracts\SubscriptionInvoices;
use Modules\SaasBilling\Contracts\SubscriptionPayments;
use Modules\SaasBilling\Contracts\SubscriptionQuotes;
use Modules\SaasBilling\Invoices\InvoiceTemplates;
use Modules\SaasBilling\Invoices\SubscriptionInvoiceList;
use Modules\SaasBilling\Jobs\MeterUnits;
use Modules\SaasBilling\Jobs\ReconcilePlatformPayments;
use Modules\SaasBilling\Listeners\InvoiceWhenActive;
use Modules\SaasBilling\Payments\SubscriptionCheckout;
use Modules\SaasBilling\Payments\WebhookReplays;
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
 * Metering and subscription invoices (P06-02): Metering\SubscriptionMetering,
 * invoiced when a subscription becomes active (Listeners\InvoiceWhenActive)
 * and by the hourly `saas-meter-units` (Jobs\MeterUnits); the invoice email
 * (template `billing.invoice_issued`); Contracts\SubscriptionInvoices for
 * Core's Settings → Subscription page. Paying them (P06-03):
 * Contracts\SubscriptionPayments (Payments\SubscriptionCheckout), one
 * settlement for every platform payment (Payments\PlatformPaymentSettlement:
 * return, webhook, replay, the hourly `saas-paystack-reconcile`), and
 * Contracts\PlatformWebhookReplays for the console.
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
        $this->app->bind(SubscriptionInvoices::class, SubscriptionInvoiceList::class);
        $this->app->bind(SubscriptionPayments::class, SubscriptionCheckout::class);
        $this->app->bind(PlatformWebhookReplays::class, WebhookReplays::class);

        $this->callAfterResolving(TemplateCatalog::class, static function (TemplateCatalog $catalog): void {
            $catalog->add(...InvoiceTemplates::definitions());
        });
    }

    public function boot(): void
    {
        parent::boot();

        Event::listen(SubscriptionStatusChanged::class, InvoiceWhenActive::class);

        // Billing periods that are over are invoiced (BILLING_PAYMENTS.md § 3): monthly on the 1st, yearly on their date.
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->job(new MeterUnits, 'billing')->name('saas-meter-units')->hourlyAt(45);
            // Payments through GoProperty's Paystack still pending are asked about again (§ 4.2: missed webhooks).
            $schedule->job(new ReconcilePlatformPayments, 'billing')->name('saas-paystack-reconcile')->hourlyAt(50);
        });
    }
}
