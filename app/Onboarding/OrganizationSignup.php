<?php

declare(strict_types=1);

namespace App\Onboarding;

use App\Enums\OnboardingStep;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Enums\TransactionTier;
use App\Models\Organization;
use App\Models\OrganizationOnboarding;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use App\Tenancy\WorkspaceLanding;
use Illuminate\Support\Facades\DB;

/**
 * Signup, organization step (ONBOARDING_COMPLIANCE.md § 2 step 2): creates
 * the organization, makes the signed-up user its first `org-owner`
 * (ARCHITECTURE.md § 6.1), opens the wizard's saved state at the Tier step
 * and the dashboard checklist. A user has at most one organization in
 * signup at a time; asking again returns that one.
 */
final readonly class OrganizationSignup
{
    public function __construct(
        private OrganizationContext $context,
        private OnboardingChecklist $checklist,
        private WorkspaceLanding $landing,
    ) {}

    public function start(User $user, OrganizationDetails $details, string $slug, ?string $phone = null): Organization
    {
        return DB::transaction(function () use ($user, $details, $slug, $phone): Organization {
            // One signup at a time per user, even with two tabs submitting together.
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $pending = $this->inProgressFor($user);

            if ($pending !== null) {
                return $pending;
            }

            $organization = new Organization([
                'name' => $details->name,
                'slug' => $slug,
                'type' => $details->type,
                'state' => $details->state,
                'lga' => $details->lga,
                'status' => OrganizationStatus::Onboarding,
                'transaction_tier' => TransactionTier::One,
                'platform_covered' => $details->platformCovered(),
            ]);
            $organization->cac_number = $details->cacNumber;
            $organization->save();

            if ($phone !== null && $user->phone === null) {
                $user->forceFill(['phone' => $phone])->save();
            }

            $user->joinOrganization($organization, OrganizationRole::Owner);
            $this->landing->remember($user, $organization);

            $this->context->run($organization, function () use ($user): void {
                OrganizationOnboarding::query()->create([
                    'started_by_user_id' => $user->id,
                    'step' => OnboardingStep::Tier,
                ]);
                $this->checklist->start();
            });

            return $organization;
        });
    }

    /** The organization this user is still signing up, if any. */
    public function inProgressFor(User $user): ?Organization
    {
        foreach ($user->organizations()->where('status', OrganizationStatus::Onboarding)->orderBy('organizations.id')->get() as $organization) {
            $open = $this->context->run($organization, fn (): bool => OrganizationOnboarding::query()->whereNull('completed_at')->exists());

            if ($open && $user->hasRoleIn($organization, OrganizationRole::Owner)) {
                return $organization;
            }
        }

        return null;
    }
}
