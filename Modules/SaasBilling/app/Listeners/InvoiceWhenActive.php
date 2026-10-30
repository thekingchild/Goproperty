<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Listeners;

use App\Enums\SubscriptionStatus;
use App\Models\Organization;
use App\Subscriptions\Events\SubscriptionStatusChanged;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\SaasBilling\Metering\SubscriptionMetering;

/**
 * Invoices a subscription as soon as it becomes `active` (P06-02;
 * BILLING_PAYMENTS.md § 2 "trial ends, invoice issued"; ONBOARDING_COMPLIANCE.md
 * § 3 "first platform invoice issued, units counted that day"): the first
 * period when the trial ends, and the period due when a customer is active
 * again after a lapse. Queued on `billing` after the change commits. A
 * period already invoiced, or not yet due, is left alone, so this and the
 * hourly Jobs\MeterUnits never bill twice.
 */
final class InvoiceWhenActive implements ShouldQueue
{
    public string $queue = 'billing';

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        private readonly OrganizationContext $context,
        private readonly SubscriptionMetering $metering,
    ) {}

    public function shouldQueue(SubscriptionStatusChanged $event): bool
    {
        return $event->to === SubscriptionStatus::Active;
    }

    public function handle(SubscriptionStatusChanged $event): void
    {
        $organization = Organization::query()->find($event->organizationId);

        if ($organization !== null) {
            $this->context->run($organization, fn () => $this->metering->billDue());
        }
    }
}
