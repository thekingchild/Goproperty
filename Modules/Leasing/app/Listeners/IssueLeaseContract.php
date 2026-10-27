<?php

declare(strict_types=1);

namespace Modules\Leasing\Listeners;

use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Modules\Leasing\Documents\LeaseContracts;
use Modules\Leasing\Events\LeaseActivated;
use Modules\Leasing\Jobs\RenderLeaseContract;
use Modules\Leasing\Models\Lease;
use Throwable;

/**
 * Issues a lease's contract when it is activated (P03-03b; ARCHITECTURE.md
 * § 6.5 "Lease/rent contract: lease activation (and re-print)"), the
 * renewal's too: once the activation has committed, records the contract
 * as issued (Documents\LeaseContracts::issue(): branding snapshot, parties,
 * clause template, verification code) and queues its PDF on `pdf`
 * (Jobs\RenderLeaseContract). After the commit, so a refused or rolled-back
 * activation issues nothing and a document problem never undoes a lease
 * (it is reported, and the contract is then issued on its first download).
 */
final readonly class IssueLeaseContract implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private OrganizationContext $context,
        private LeaseContracts $contracts,
    ) {}

    public function handle(LeaseActivated $event): void
    {
        $organization = Organization::query()->find($event->organizationId);

        if ($organization === null) {
            return;
        }

        try {
            $contract = $this->context->run($organization, function () use ($event) {
                $lease = Lease::query()->find($event->leaseId);

                return $lease instanceof Lease ? $this->contracts->issue($lease) : null;
            });
        } catch (Throwable $exception) {
            // The lease is active either way; the contract is issued when it is first downloaded.
            report($exception);

            return;
        }

        if ($contract !== null) {
            RenderLeaseContract::dispatch($organization->id, $contract->id);
        }
    }
}
