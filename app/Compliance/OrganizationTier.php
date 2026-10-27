<?php

declare(strict_types=1);

namespace App\Compliance;

use App\Enums\TransactionTier;
use App\Models\Organization;
use App\Models\PlatformStaff;
use App\Models\TierApplication;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of `organizations.transaction_tier`, the organization's
 * approved tier (DATABASE_SCHEMA.md § 1, ONBOARDING_COMPLIANCE.md § 5).
 * Today only an approved application changes it
 * (App\Platform\Compliance\TierReview::approve). One audit entry per change
 * (log `compliance`, `organization.tier_changed`) with the staff member,
 * the reason and the tier before and after; the model's own change entry
 * is suppressed so the change is logged once.
 */
final readonly class OrganizationTier
{
    public function __construct(private ComplianceAudit $audit) {}

    public function change(Organization $organization, TransactionTier $to, PlatformStaff $by, string $reason, ?TierApplication $application = null): Organization
    {
        return DB::transaction(function () use ($organization, $to, $by, $reason, $application): Organization {
            $locked = Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
            $from = $locked->transaction_tier;

            if ($from === $to) {
                return $locked;
            }

            $locked->forceFill(['transaction_tier' => $to]);
            $locked->disableLogging();

            try {
                $locked->save();
            } finally {
                $locked->enableLogging();
            }

            $this->audit->record('organization.tier_changed', $locked, $by, "{$locked->name} moved from {$from->label()} to {$to->label()}", array_filter([
                'old' => ['transaction_tier' => $from->value],
                'attributes' => ['transaction_tier' => $to->value],
                'tier_application_id' => $application?->id,
            ], static fn (mixed $value): bool => $value !== null), $reason);

            return $locked;
        });
    }
}
