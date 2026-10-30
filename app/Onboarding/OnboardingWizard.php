<?php

declare(strict_types=1);

namespace App\Onboarding;

use App\Enums\ChecklistItem;
use App\Enums\OnboardingStep;
use App\Enums\OrganizationStatus;
use App\Enums\TransactionTier;
use App\Marketing\Plan;
use App\Models\Organization;
use App\Models\OrganizationOnboarding;
use App\Models\User;
use App\Sms\Credits\SmsCredits;
use App\Subscriptions\SubscriptionService;
use App\Support\Money;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The signup wizard after the organization exists (ONBOARDING_COMPLIANCE.md
 * § 2 steps 3–6), for the organization in the current OrganizationContext.
 * Every step is saved in `organization_onboardings` before moving on, so
 * leaving and signing in again resumes on the same step. Steps are saved
 * in order: saving one that is not the current step throws StepOutOfOrder.
 * Authorization is the caller's (OrganizationOnboardingPolicy).
 *
 * Boundaries with later tasks:
 * - Tier 2/3 is recorded as requested; the organization keeps its approved
 *   tier (Tier 1) until compliance approves documents (P01-03).
 * - The plan step starts the `trialing` subscription (P06-01b, through
 *   SubscriptionService) on the chosen plan, for the plan's trial days from
 *   the first choice; `organizations.signup_plan_key` / `trial_ends_at`
 *   keep the signup record.
 * - Collections and data can only be put off for now; their checklist
 *   items stay pending until those features mark them done.
 */
final readonly class OnboardingWizard
{
    public function __construct(
        private OrganizationContext $context,
        private OnboardingChecklist $checklist,
        private SmsCredits $smsCredits,
        private SubscriptionService $subscriptions,
    ) {}

    /** The wizard state of the current organization, or null for one made outside signup. */
    public function state(): ?OrganizationOnboarding
    {
        return OrganizationOnboarding::query()->first();
    }

    public function isOpen(): bool
    {
        return OrganizationOnboarding::query()->whereNull('completed_at')->exists();
    }

    /** Organization step, when coming back to it: name, type, place, CAC number. The slug stays. */
    public function updateOrganization(OrganizationDetails $details): void
    {
        $this->save(OnboardingStep::Organization, function (OrganizationOnboarding $state) use ($details): void {
            $organization = $this->organization();
            $organization->fill([
                'name' => $details->name,
                'type' => $details->type,
                'state' => $details->state,
                'lga' => $details->lga,
                'platform_covered' => $details->platformCovered(),
            ]);
            $organization->cac_number = $details->cacNumber;
            $organization->save();
        });
    }

    /**
     * Tier step: the stated yearly collections and the tier asked for.
     * Tier 1 is active at once; Tier 2/3 stays requested, and its checklist
     * item open, until compliance approves the documents (P01-03).
     */
    public function chooseTier(User $by, Money $declaredAnnualVolume, TransactionTier $requested): void
    {
        $this->save(OnboardingStep::Tier, function (OrganizationOnboarding $state) use ($by, $declaredAnnualVolume, $requested): void {
            $state->declared_annual_volume_kobo = $declaredAnnualVolume;
            $state->requested_tier = $requested;

            if ($requested === TransactionTier::One) {
                $this->checklist->complete(ChecklistItem::Tier, $by, ['requested_tier' => $requested->value]);
            } else {
                $this->checklist->reopen(ChecklistItem::Tier, ['requested_tier' => $requested->value]);
            }
        });
    }

    /** Plan step: the plan, and the free trial, which starts the first time a plan is chosen. */
    public function choosePlan(User $by, Plan $plan): void
    {
        $this->save(OnboardingStep::Plan, function () use ($by, $plan): void {
            $organization = $this->organization();
            $organization->signup_plan_key = $plan->key;
            $organization->trial_ends_at ??= now()->addDays($plan->trialDays);
            $organization->save();

            $this->subscriptions->start($by, $plan, CarbonImmutable::instance($organization->trial_ends_at));

            $this->checklist->complete(ChecklistItem::Plan, $by, ['plan' => $plan->key]);
        });

        // A bigger plan's SMS allowance from now (the difference this month; P05-03).
        $this->smsCredits->refreshAllowance();
    }

    /** Collections step, "Set up later": the checklist item stays pending. */
    public function setUpCollectionsLater(): void
    {
        $this->save(OnboardingStep::Collections, fn (): null => null);
    }

    /**
     * Data step, the last one, "Do this later": the wizard is over, the
     * organization is active and the dashboard checklist takes over.
     */
    public function finish(): void
    {
        DB::transaction(function (): void {
            $state = $this->lockedState(OnboardingStep::Data);
            $state->completed_at = now();
            $state->save();

            $organization = $this->organization();

            if ($organization->status === OrganizationStatus::Onboarding) {
                $organization->status = OrganizationStatus::Active;
                $organization->save();
            }
        });
    }

    /** Back to an earlier step; what was saved there is kept. */
    public function goBackTo(OnboardingStep $step): void
    {
        DB::transaction(function () use ($step): void {
            $state = $this->lockedState();

            if (! $step->isOrganizationStep() || ! $step->isBefore($state->step)) {
                throw new StepOutOfOrder("Cannot go back from {$state->step->value} to {$step->value}.");
            }

            $state->step = $step;
            $state->save();
        });
    }

    /**
     * Runs one step's changes and moves to the next step, in one
     * transaction with the state row locked.
     *
     * @param  callable(OrganizationOnboarding): mixed  $changes
     */
    private function save(OnboardingStep $step, callable $changes): void
    {
        DB::transaction(function () use ($step, $changes): void {
            $state = $this->lockedState($step);
            $changes($state);
            $state->step = $step->next() ?? $step;
            $state->save();
        });
    }

    private function lockedState(?OnboardingStep $expected = null): OrganizationOnboarding
    {
        $state = OrganizationOnboarding::query()->lockForUpdate()->first();

        if ($state === null || $state->isComplete()) {
            throw new StepOutOfOrder('This organization has no signup in progress.');
        }

        if ($expected !== null && $state->step !== $expected) {
            throw new StepOutOfOrder("The current step is {$state->step->value}, not {$expected->value}.");
        }

        return $state;
    }

    private function organization(): Organization
    {
        return Organization::query()->whereKey($this->context->id())->lockForUpdate()->firstOrFail();
    }
}
