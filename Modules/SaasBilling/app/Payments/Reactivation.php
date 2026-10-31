<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Payments;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Subscriptions\SubscriptionChangeRefused;
use App\Subscriptions\SubscriptionService;
use Carbon\CarbonImmutable;
use Modules\SaasBilling\Enums\InvoiceKind;
use Modules\SaasBilling\Enums\InvoiceStatus;
use Modules\SaasBilling\Models\PlatformInvoice;

/**
 * BILLING_PAYMENTS.md § 6 "Reactivation", the one rule for every way a
 * subscription invoice is paid (Paystack, P06-03; a bank transfer staff
 * confirmed, P06-04): a lapsed subscription (`past_due`, `grace`,
 * `suspended`) is active again once no other subscription invoice is
 * overdue (SubscriptionService::activate(), which restores an organization
 * the subscription suspended). A cancelled one is reactivated by staff
 * (P06-07). Runs inside the paying transaction, in the invoice's
 * organization.
 */
final readonly class Reactivation
{
    public function __construct(private SubscriptionService $subscriptions) {}

    /** Whether the subscription became active again because $invoice was paid. */
    public function afterPaid(PlatformInvoice $invoice): bool
    {
        $subscription = Subscription::query()->first();
        $lapsed = [SubscriptionStatus::PastDue, SubscriptionStatus::Grace, SubscriptionStatus::Suspended];

        if (! $subscription instanceof Subscription || ! in_array($subscription->status, $lapsed, true)) {
            return false;
        }

        $stillOverdue = PlatformInvoice::query()
            ->where('kind', InvoiceKind::Subscription)
            ->where('status', InvoiceStatus::Open)
            ->whereDate('due_date', '<', CarbonImmutable::now('Africa/Lagos')->toDateString())
            ->exists();

        if ($stillOverdue) {
            return false;
        }

        try {
            $this->subscriptions->activate(null, "Invoice {$invoice->number} paid");
        } catch (SubscriptionChangeRefused) {
            return false;
        }

        return true;
    }
}
