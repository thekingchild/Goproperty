<?php

declare(strict_types=1);

namespace App\Compliance\Monitoring;

use App\Compliance\ComplianceAudit;
use App\Enums\ComplianceFlagStatus;
use App\Enums\ComplianceFlagType;
use App\Enums\OrganizationStatus;
use App\Enums\RestrictionSource;
use App\Enums\TierApplicationStatus;
use App\Models\ComplianceFlag;
use App\Models\Organization;
use App\Models\PlatformStaff;
use App\Models\TierApplication;
use App\Notifications\TierMonitoringNotice;
use App\Platform\Compliance\TierSettingsRefused;
use App\Platform\Staff\StaffPermission;
use App\Tenancy\OrganizationContext;
use App\Tenancy\OrganizationOwners;
use Illuminate\Support\Facades\DB;

/**
 * Restricting an organization for its Transaction Tier (P06-09;
 * ONBOARDING_COMPLIANCE.md § 5.5, defaults 96–98), the only writer of
 * `organizations.restriction_source` / `restricted_at`.
 *
 * - **The monitoring** (reconcile(), from TierMonitor and whenever an
 *   application is submitted): an `active` organization whose open
 *   `volume_100` flag's grace has ended, with no upgrade application sent
 *   (submitted, under review or waiting for more information: a draft does
 *   not count), becomes `restricted` (`tier_monitoring`); it goes back to
 *   `active` once that no longer holds (the volume fell back and the flag
 *   resolved, a higher tier or limit applies, an application was sent).
 *   An organization under review is never restricted.
 * - **Staff** (restrict() / lift(), `compliance.restrict`, a reason): only
 *   an `active` organization is restricted (`staff`); the monitoring never
 *   lifts it. Lifting either kind needs staff; a lifted monitoring
 *   restriction comes back on the next run if its reason still holds.
 *
 * Suspended and cancelled organizations are never touched (their status
 * before suspension is kept). Each change is one audit entry (log
 * `compliance`, `organization.restricted` / `organization.restriction_lifted`,
 * the staff member or none, the reason, before and after), and the owners
 * are told in the app and by email after the commit.
 */
final readonly class TierRestrictions
{
    public const int REASON_MIN = 3;

    public const int REASON_MAX = 500;

    public function __construct(
        private OrganizationContext $context,
        private ComplianceAudit $audit,
        private OrganizationOwners $owners,
    ) {}

    /**
     * Brings the monitoring's restriction in line with the flags. Call
     * inside the organization's context, in a transaction holding its lock.
     */
    public function reconcile(Organization $locked): void
    {
        $restrict = $this->graceEnded($locked) && ! $this->applicationSent();

        if ($restrict && $locked->status === OrganizationStatus::Active) {
            $this->change($locked, OrganizationStatus::Restricted, RestrictionSource::TierMonitoring, null, 'Its yearly collections are over its Transaction Tier limit and the grace period ended without an upgrade application.');

            return;
        }

        if (! $restrict && $locked->status === OrganizationStatus::Restricted && $locked->restriction_source === RestrictionSource::TierMonitoring) {
            $this->change($locked, OrganizationStatus::Active, null, null, $this->applicationSent()
                ? 'An upgrade application was sent, so the organization is not restricted while it is reviewed.'
                : 'Its yearly collections are within its Transaction Tier limit again.');
        }
    }

    /** reconcile() in its own transaction (after an application is submitted or a limit changes). */
    public function reconcileNow(Organization $organization): void
    {
        $this->context->run($organization, fn () => DB::transaction(function () use ($organization): void {
            $this->reconcile(self::lock($organization));
        }, 3));
    }

    /** @throws TierSettingsRefused */
    public function restrict(PlatformStaff $staff, Organization $organization, string $reason): Organization
    {
        $reason = $this->checked($staff, $reason);

        return $this->context->run($organization, fn (): Organization => DB::transaction(function () use ($staff, $organization, $reason): Organization {
            $locked = self::lock($organization);

            if ($locked->status !== OrganizationStatus::Active) {
                throw new TierSettingsRefused("{$locked->name} is {$locked->status->value}: only an active organization can be restricted.");
            }

            $this->change($locked, OrganizationStatus::Restricted, RestrictionSource::Staff, $staff, $reason);

            return $locked;
        }, 3));
    }

    /** @throws TierSettingsRefused */
    public function lift(PlatformStaff $staff, Organization $organization, string $reason): Organization
    {
        $reason = $this->checked($staff, $reason);

        return $this->context->run($organization, fn (): Organization => DB::transaction(function () use ($staff, $organization, $reason): Organization {
            $locked = self::lock($organization);

            if ($locked->status !== OrganizationStatus::Restricted) {
                throw new TierSettingsRefused("{$locked->name} is not restricted.");
            }

            $this->change($locked, OrganizationStatus::Active, null, $staff, $reason);

            return $locked;
        }, 3));
    }

    /** Whether the open `volume_100` flag of the organization's tier has run out of grace. */
    private function graceEnded(Organization $locked): bool
    {
        return ComplianceFlag::query()
            ->where('type', ComplianceFlagType::Volume100->value)
            ->whereIn('status', ComplianceFlagStatus::openValues())
            ->where('tier', $locked->transaction_tier->value)
            ->whereNotNull('grace_ends_at')
            ->where('grace_ends_at', '<=', now())
            ->exists();
    }

    /** An upgrade application the owner has sent and compliance has not decided. */
    private function applicationSent(): bool
    {
        return TierApplication::query()
            ->whereIn('status', [TierApplicationStatus::Submitted->value, TierApplicationStatus::UnderReview->value, TierApplicationStatus::MoreInfoNeeded->value])
            ->exists();
    }

    private function change(Organization $locked, OrganizationStatus $to, ?RestrictionSource $source, ?PlatformStaff $staff, string $reason): void
    {
        $from = $locked->status;
        $before = $locked->restriction_source;

        $locked->forceFill([
            'status' => $to,
            'restriction_source' => $source,
            'restricted_at' => $source !== null ? now() : null,
        ]);
        $locked->disableLogging();

        try {
            $locked->save();
        } finally {
            $locked->enableLogging();
        }

        $restricted = $to === OrganizationStatus::Restricted;
        $this->audit->record($restricted ? 'organization.restricted' : 'organization.restriction_lifted', $locked, $staff, $restricted ? "Restricted {$locked->name}" : "Lifted the restriction on {$locked->name}", [
            'old' => ['status' => $from->value, 'restriction_source' => $before?->value],
            'attributes' => ['status' => $to->value, 'restriction_source' => $source?->value],
        ], $reason);

        $notice = $restricted
            ? TierMonitoringNotice::restricted($locked, $source === RestrictionSource::Staff)
            : TierMonitoringNotice::restrictionLifted($locked);

        foreach ($this->owners->owners($locked) as $owner) {
            $owner->notify($notice);
        }
    }

    /** @throws TierSettingsRefused */
    private function checked(PlatformStaff $staff, string $reason): string
    {
        if (! $staff->holdsStaffPermission(StaffPermission::CustomersView) || ! $staff->holdsStaffPermission(StaffPermission::ComplianceRestrict)) {
            throw new TierSettingsRefused('You do not have permission to restrict organizations.');
        }

        $reason = trim($reason);

        if (mb_strlen($reason) < self::REASON_MIN || mb_strlen($reason) > self::REASON_MAX) {
            throw new TierSettingsRefused('Give a reason of '.self::REASON_MIN.' to '.self::REASON_MAX.' characters: it is kept in the audit log.');
        }

        return $reason;
    }

    private static function lock(Organization $organization): Organization
    {
        return Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
    }
}
