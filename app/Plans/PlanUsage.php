<?php

declare(strict_types=1);

namespace App\Plans;

use App\Enums\InvitationKind;
use App\Enums\InvitationStatus;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use LogicException;

/**
 * How much of a counted limit an organization uses (P06-01b). Staff users:
 * its members and the member invitations still waiting (so a burst of
 * invitations cannot go over the limit once accepted; docs/plans/
 * P06-subtasks.md default 7). SMS credits are a monthly allowance, not a
 * count: App\Sms\Credits\SmsCredits reads that limit.
 */
final readonly class PlanUsage
{
    public function __construct(private OrganizationContext $context) {}

    public function count(Feature $feature, Organization $organization): int
    {
        return match ($feature) {
            Feature::StaffUsers => $this->context->run($organization, fn (): int => $this->members() + $this->waitingInvitations()),
            default => throw new LogicException("{$feature->value} is not a counted limit."),
        };
    }

    /** Members only, without waiting invitations: what an acceptance is checked against. */
    public function members(): int
    {
        return Membership::query()->count();
    }

    private function waitingInvitations(): int
    {
        return Invitation::query()
            ->where('kind', InvitationKind::User)
            ->where('status', InvitationStatus::Pending)
            ->where('expires_at', '>', now())
            ->count();
    }
}
