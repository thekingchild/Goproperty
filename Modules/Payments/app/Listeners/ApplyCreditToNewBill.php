<?php

declare(strict_types=1);

namespace Modules\Payments\Listeners;

use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Modules\Billing\Events\BillIssued;
use Modules\Payments\Credit\TenantCredit;

/**
 * A bill was issued (Billing's BillIssued): apply the tenant's credit to it
 * (ARCHITECTURE.md § 6.4: "excess becomes tenant credit", used by the next
 * bill). Queued on `billing` once the issuing transaction has committed,
 * so a failure here never undoes the bill, and retried with a backoff.
 * Safe to run twice (AGENT.md § 3 rule 6): Credit\TenantCredit applies
 * credit to a bill at most once. Runs in the bill's organization.
 */
final class ApplyCreditToNewBill implements ShouldQueueAfterCommit
{
    public string $queue = 'billing';

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(
        private readonly OrganizationContext $context,
        private readonly TenantCredit $credit,
    ) {}

    public function handle(BillIssued $event): void
    {
        $organization = Organization::query()->find($event->organizationId);

        if (! $organization instanceof Organization) {
            return;
        }

        $this->context->run($organization, fn () => $this->credit->applyToBill($event->billId));
    }
}
