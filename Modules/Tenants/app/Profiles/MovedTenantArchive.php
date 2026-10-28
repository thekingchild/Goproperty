<?php

declare(strict_types=1);

namespace Modules\Tenants\Profiles;

use App\Support\Money;
use App\Tenancy\Moves\MovedTenant;
use App\Tenancy\Moves\MovedTenants;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Contracts\BillLedger;
use Modules\Leasing\Contracts\TenantLeases;
use Modules\Payments\Contracts\HandoverCredits;
use Modules\Tenants\Models\TenantProfile;

/**
 * The record a tenant left behind when they moved with their building
 * (BILLING_PAYMENTS.md § 7.4, product owner 2026-10-28, P04-04): it keeps
 * its history and shows "Moved to {organization} on {date}" (where they
 * went comes from Core's MovedTenants, answered by the Handovers module).
 *
 * - It cannot be archived while it has unpaid bills here (the tenant still
 *   pays them here, from the page the record gives them) or credit here
 *   (owed to the tenant until the handover's credit is settled): refusal().
 * - It archives itself once nothing is owed either way and no lease here
 *   holds a unit for the tenant: archiveIfSettled(), called when a payment
 *   is confirmed, a bill voided, the tenants moved out or their credit was
 *   settled (Listeners\ArchiveSettledMovedTenants), and by the daily sweep
 *   (Jobs\ArchiveSettledMovedTenants) for anything those miss.
 *
 * Unpaid bills are read through Billing's BillLedger, credit through
 * Payments' HandoverCredits, leases through Leasing's TenantLeases, all in
 * the current organization. Archiving is the usual soft delete, audited
 * (`tenant.archived`, by the system, reason `moved_and_settled`); a tenant
 * who comes back with a building gets the record back (the Tenants mover
 * restores it).
 */
final readonly class MovedTenantArchive
{
    /** Where the tenant of this record moved, or null when they did not (or came back). */
    public function movedTo(int $tenantProfileId): ?MovedTenant
    {
        if (! app()->bound(MovedTenants::class)) {
            return null;
        }

        return app(MovedTenants::class)->movedOut([$tenantProfileId])[$tenantProfileId] ?? null;
    }

    /** Why this record may not be archived yet, or null (the tenant did not move, or nothing is owed either way). */
    public function refusal(TenantProfile $tenant): ?string
    {
        $moved = $this->movedTo($tenant->id);

        if ($moved === null) {
            return null;
        }

        [$bills, $owed] = $this->unpaid($tenant->id);
        $credit = $this->credit($tenant->id);
        $name = $tenant->fullName();
        $organization = $moved->organizationName;
        $date = $moved->movedAt->timezone('Africa/Lagos')->format('j F Y');

        if ($bills > 0 && $credit->isPositive()) {
            return __(':name moved to :organization on :date but still owes :owed on unpaid bills here and has :credit of credit here. The record stays until both are settled; then it archives itself.', ['name' => $name, 'organization' => $organization, 'date' => $date, 'owed' => $owed->format(), 'credit' => $credit->format()]);
        }

        if ($bills > 0) {
            return __(':name moved to :organization on :date but still owes :owed on unpaid bills here: they pay them from this record. It archives itself once they are paid.', ['name' => $name, 'organization' => $organization, 'date' => $date, 'owed' => $owed->format()]);
        }

        if ($credit->isPositive()) {
            return __(':name moved to :organization on :date and has :credit of credit here that is not settled yet (on the handover\'s page). The record archives itself once it is.', ['name' => $name, 'organization' => $organization, 'date' => $date, 'credit' => $credit->format()]);
        }

        return null;
    }

    /**
     * Archives the record when its tenant moved out, no lease here holds a
     * unit for them, and nothing is owed either way. Safe to run again;
     * inside the current organization.
     */
    public function archiveIfSettled(int $tenantProfileId): bool
    {
        $moved = $this->movedTo($tenantProfileId);

        if ($moved === null) {
            return false;
        }

        return DB::transaction(function () use ($tenantProfileId, $moved): bool {
            $tenant = TenantProfile::query()->whereKey($tenantProfileId)->lockForUpdate()->first();

            if ($tenant === null
                || (app()->bound(TenantLeases::class) && app(TenantLeases::class)->holdsUnit($tenant->id))
                || $this->unpaid($tenant->id)[0] > 0
                || $this->credit($tenant->id)->isPositive()) {
                return false;
            }

            $tenant->delete();

            activity(TenantProfiles::LOG)
                ->event('tenant.archived')
                ->performedOn($tenant)
                ->causedByAnonymous()
                ->withProperties([
                    'reason' => 'moved_and_settled',
                    'moved_to' => $moved->organizationName,
                    'handover_id' => $moved->handoverId,
                ])
                ->log("Tenant archived by itself: moved to {$moved->organizationName} and nothing is owed either way");

            return true;
        });
    }

    /**
     * Archives every record of the current organization whose tenant moved
     * out and owes and is owed nothing.
     *
     * @return int how many were archived
     */
    public function sweep(): int
    {
        if (! app()->bound(MovedTenants::class)) {
            return 0;
        }

        $archived = 0;
        $live = TenantProfile::query()->whereKey(app(MovedTenants::class)->movedOutIds())->orderBy('id')->pluck('id');

        foreach ($live as $tenantId) {
            $archived += $this->archiveIfSettled((int) $tenantId) ? 1 : 0;
        }

        return $archived;
    }

    /** @return array{int, Money} how many bills still ask for money, and how much */
    private function unpaid(int $tenantProfileId): array
    {
        if (! app()->bound(BillLedger::class)) {
            return [0, Money::zero()];
        }

        $open = app(BillLedger::class)->openBills($tenantProfileId);

        return [count($open), Money::sum(array_map(static fn ($bill): Money => $bill->outstanding, $open))];
    }

    private function credit(int $tenantProfileId): Money
    {
        if (! app()->bound(HandoverCredits::class)) {
            return Money::zero();
        }

        return app(HandoverCredits::class)->balances([$tenantProfileId])[$tenantProfileId] ?? Money::zero();
    }
}
