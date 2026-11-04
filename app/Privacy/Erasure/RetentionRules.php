<?php

declare(strict_types=1);

namespace App\Privacy\Erasure;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * When personal data may be erased (P09-02; DATABASE_SCHEMA.md rule 6,
 * BILLING_PAYMENTS.md § 2: a cancelled organization's data is kept 90
 * days, then purged by the NDPA tooling; docs/plans/P09-subtasks.md
 * defaults 18–20):
 *
 * - an **organization** is purged only once it is cancelled (its
 *   subscription ended) or deleted, and only 90 days after the later of
 *   the two; reactivated in between, it is not purged at all;
 * - a **tenant** is erased only once no lease of theirs is a draft or
 *   active and no bill of theirs is unpaid (the agency may still need to
 *   recover money), and only 90 days after their last lease ended (or
 *   after their record was made, with no lease).
 *
 * Read with the query builder, since the rules span modules' tables; no
 * state changes here.
 */
final class RetentionRules
{
    public const int DAYS = 90;

    /** Why the tenant may not be erased now, or null. */
    public function tenantRefusal(int $organizationId, int $tenantProfileId): ?string
    {
        $tenant = DB::table('tenant_profiles')->where('organization_id', $organizationId)->where('id', $tenantProfileId)->first(['id']);

        if ($tenant === null) {
            return 'That organization has no such tenant.';
        }

        if ($this->tenantErased($organizationId, $tenantProfileId)) {
            return 'This tenant\'s data has already been erased.';
        }

        if (DB::table('leases')->where('organization_id', $organizationId)->where('tenant_profile_id', $tenantProfileId)->whereNull('deleted_at')->whereIn('status', ['draft', 'active'])->exists()) {
            return 'The tenant still has a lease that is active or not yet signed: end it first.';
        }

        if (DB::table('bills')->where('organization_id', $organizationId)->where('tenant_profile_id', $tenantProfileId)->whereNull('deleted_at')->whereIn('status', ['unpaid', 'partially_paid', 'overdue'])->exists()) {
            return 'The tenant still has unpaid bills: the organization may need them to recover the money.';
        }

        return null;
    }

    /** The first moment the tenant's data may be erased. */
    public function tenantDueAt(int $organizationId, int $tenantProfileId): CarbonImmutable
    {
        $ended = null;

        foreach (DB::table('leases')->where('organization_id', $organizationId)->where('tenant_profile_id', $tenantProfileId)->get(['end_date', 'expired_at', 'terminated_at']) as $lease) {
            foreach ([$lease->terminated_at, $lease->expired_at, $lease->end_date] as $value) {
                if (is_string($value) && $value !== '') {
                    $at = CarbonImmutable::parse($value);
                    $ended = $ended === null || $at->greaterThan($ended) ? $at : $ended;

                    // The earliest of a lease's own end markers that is set: terminated, expired, end date.
                    break;
                }
            }
        }

        if ($ended === null) {
            $created = DB::table('tenant_profiles')->where('id', $tenantProfileId)->value('created_at');
            $ended = is_string($created) ? CarbonImmutable::parse($created) : CarbonImmutable::now();
        }

        return $ended->addDays(self::DAYS);
    }

    public function tenantErased(int $organizationId, int $tenantProfileId): bool
    {
        return DB::table('data_requests')->where('organization_id', $organizationId)->where('tenant_profile_id', $tenantProfileId)
            ->where('kind', 'erasure')->where('status', 'completed')->exists();
    }

    /** Why the organization may not be purged now, or null. */
    public function organizationRefusal(int $organizationId): ?string
    {
        $organization = DB::table('organizations')->where('id', $organizationId)->first(['status', 'deleted_at']);

        if ($organization === null) {
            return 'There is no such organization.';
        }

        if ($this->organizationPurged($organizationId)) {
            return 'This organization\'s data has already been purged.';
        }

        if ($organization->deleted_at === null && $organization->status !== 'cancelled') {
            return 'Only a cancelled or deleted organization is purged: this one is '.$organization->status.'.';
        }

        return null;
    }

    /** The first moment the organization's data may be purged. */
    public function organizationDueAt(int $organizationId): CarbonImmutable
    {
        $organization = DB::table('organizations')->where('id', $organizationId)->first(['status', 'deleted_at', 'updated_at']);
        $starts = [];

        if (is_string($organization->deleted_at ?? null)) {
            $starts[] = CarbonImmutable::parse($organization->deleted_at);
        }

        if (($organization->status ?? null) === 'cancelled') {
            $ended = DB::table('subscriptions')->where('organization_id', $organizationId)->where('status', 'cancelled')->max('ended_at');
            $starts[] = CarbonImmutable::parse(is_string($ended) ? $ended : (string) ($organization->updated_at ?? 'now'));
        }

        $start = $starts === [] ? CarbonImmutable::now() : max($starts);

        return $start->addDays(self::DAYS);
    }

    public function organizationPurged(int $organizationId): bool
    {
        return DB::table('data_requests')->where('organization_id', $organizationId)->where('scope', 'organization')
            ->where('kind', 'erasure')->where('status', 'completed')->exists();
    }
}
