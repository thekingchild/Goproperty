<?php

declare(strict_types=1);

namespace Modules\Tenants\Listeners;

use App\Models\Organization;
use App\Tenancy\Moves\MovedTenantsChanged;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Modules\Billing\Events\BillVoided;
use Modules\Payments\Events\PaymentConfirmed;
use Modules\Tenants\Profiles\MovedTenantArchive;
use Throwable;

/**
 * A moved tenant's record left behind archives itself as soon as nothing
 * is owed either way (BILLING_PAYMENTS.md § 7.4, P04-04): checked when a
 * payment of theirs is confirmed (their bills here may now be paid), a
 * bill of theirs is voided, they moved out, or the credit they held at the
 * handover was settled (Core's MovedTenantsChanged, from the Handovers
 * module). After the commit; a failure here is reported and left to the
 * daily sweep, never undoing what triggered it.
 */
final readonly class ArchiveSettledMovedTenants implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private OrganizationContext $context,
        private MovedTenantArchive $archive,
    ) {}

    public function handle(PaymentConfirmed|BillVoided|MovedTenantsChanged $event): void
    {
        [$organizationId, $tenantIds] = match (true) {
            $event instanceof MovedTenantsChanged => [$event->organizationId, $event->tenantProfileIds],
            default => [$event->organizationId, [$event->tenantProfileId]],
        };

        $organization = Organization::query()->find($organizationId);

        if ($organization === null) {
            return;
        }

        try {
            $this->context->run($organization, function () use ($tenantIds): void {
                foreach ($tenantIds as $tenantId) {
                    $this->archive->archiveIfSettled($tenantId);
                }
            });
        } catch (Throwable $failure) {
            report($failure);
        }
    }
}
