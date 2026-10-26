<?php

declare(strict_types=1);

namespace App\Livewire\Onboarding;

use App\Enums\OnboardingStep;
use App\Enums\OrganizationType;
use App\Enums\TransactionTier;
use App\Marketing\Plan;
use App\Marketing\PlanCatalogue;
use App\Models\Organization;
use App\Models\OrganizationOnboarding;
use App\Models\User;
use App\Onboarding\OnboardingWizard;
use App\Onboarding\StepOutOfOrder;
use App\Onboarding\TierGuide;
use App\Support\Money;
use App\Support\NigerianStates;
use App\Tenancy\OrganizationContext;
use App\Theme\ThemeContext;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

/**
 * The signup wizard inside the new workspace, /o/{organization}/setup
 * (ONBOARDING_COMPLIANCE.md § 2 steps 3–6): Transaction Tier, plan and
 * trial, rent collection, properties, with "Back" to any earlier step
 * including the organization details. The step shown is the one saved in
 * `organization_onboardings`, so leaving and coming back resumes there.
 *
 * Runs inside the `organization` middleware, replayed for Livewire updates
 * (AppServiceProvider): every request has the organization's context, and
 * the component refuses one for another organization. Every action checks
 * OrganizationPolicy::continueSignup for its step.
 */
#[Layout('layouts::onboarding')]
#[Title('Set up your organization')]
final class SetupWizard extends Component
{
    #[Locked]
    public int $organizationId;

    public OrganizationForm $form;

    /** Expected yearly collections, in naira as typed ("50,000,000"). */
    public string $annualVolume = '';

    public ?int $tier = null;

    public string $plan = '';

    #[Locked]
    public ?string $notice = null;

    public function boot(OrganizationContext $context): void
    {
        abort_unless($context->has(), 404);
    }

    public function hydrate(OrganizationContext $context): void
    {
        abort_unless($context->id() === $this->organizationId, 404);
    }

    public function mount(OrganizationContext $context, OnboardingWizard $wizard, PlanCatalogue $plans): void
    {
        $organization = $context->get();
        $this->organizationId = $organization->id;
        $state = $wizard->state();

        if ($state === null || $state->isComplete()) {
            $this->redirectRoute('workspace.home', $organization);

            return;
        }

        $this->authorize('continueSignup', [$organization, $state->step]);
        $this->fillFrom($organization, $state, $plans);
    }

    public function saveOrganization(OnboardingWizard $wizard): void
    {
        $this->authorizeStep(OnboardingStep::Organization);
        $details = $this->form->details();

        $this->attempt(fn () => $wizard->updateOrganization($details));
    }

    public function saveTier(OnboardingWizard $wizard): void
    {
        $this->authorizeStep(OnboardingStep::Tier);

        $this->validate([
            'annualVolume' => ['required', 'string', 'max:24'],
            'tier' => ['required', 'integer', Rule::enum(TransactionTier::class)],
        ], [], ['annualVolume' => 'expected yearly collections', 'tier' => 'tier']);

        $volume = $this->declaredVolume() ?? throw ValidationException::withMessages([
            'annualVolume' => 'Enter an amount in naira, such as 25,000,000.',
        ]);

        $this->attempt(fn () => $wizard->chooseTier($this->user(), $volume, TransactionTier::from((int) $this->tier)));
    }

    public function savePlan(OnboardingWizard $wizard, PlanCatalogue $plans): void
    {
        $this->authorizeStep(OnboardingStep::Plan);

        $keys = array_map(static fn (Plan $plan): string => $plan->key, $plans->plans());
        $this->validate(['plan' => ['required', Rule::in($keys)]], ['plan.required' => 'Choose a plan.'], ['plan' => 'plan']);

        $chosen = collect($plans->plans())->firstOrFail(fn (Plan $plan): bool => $plan->key === $this->plan);

        $this->attempt(fn () => $wizard->choosePlan($this->user(), $chosen));
    }

    public function setUpCollectionsLater(OnboardingWizard $wizard): void
    {
        $this->authorizeStep(OnboardingStep::Collections);

        $this->attempt(fn () => $wizard->setUpCollectionsLater());
    }

    public function finish(OnboardingWizard $wizard, OrganizationContext $context): void
    {
        $this->authorizeStep(OnboardingStep::Data);

        if ($this->attempt(fn () => $wizard->finish())) {
            session()->flash('status', 'Your workspace is ready. The checklist shows what is left to set up.');
            $this->redirectRoute('workspace.home', $context->get());
        }
    }

    public function back(OnboardingWizard $wizard): void
    {
        $state = $this->state($wizard);
        $previous = $state->step->previous();

        if ($previous === null || ! $previous->isOrganizationStep()) {
            return;
        }

        $this->authorizeStep($previous);
        $this->attempt(fn () => $wizard->goBackTo($previous));
    }

    public function render(OnboardingWizard $wizard, TierGuide $tiers, PlanCatalogue $plans, OrganizationContext $context): View
    {
        $state = $wizard->state();
        $organization = Organization::query()->whereKey($context->id())->firstOrFail();
        $volume = $this->declaredVolume();

        return view('livewire.onboarding.setup-wizard', [
            'organization' => $organization,
            'step' => $state === null ? OnboardingStep::Data : $state->step,
            'types' => OrganizationType::cases(),
            'states' => NigerianStates::ALL,
            'tierOptions' => $tiers->options(),
            'recommendedTier' => $volume !== null ? $tiers->recommend($volume) : null,
            'plans' => $plans->plans(),
            'trialDays' => $plans->trialDays(),
            'brand' => app(ThemeContext::class)->current()->name(),
            'host' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'goproperty.ng',
        ]);
    }

    /** The amount typed, in kobo, or null when it is not a naira amount. */
    private function declaredVolume(): ?Money
    {
        $typed = str_replace([' ', "\u{00A0}"], '', trim($this->annualVolume));

        if ($typed === '') {
            return null;
        }

        try {
            $amount = Money::naira($typed);
        } catch (Throwable) {
            return null;
        }

        return $amount->isNegative() ? null : $amount;
    }

    private function fillFrom(Organization $organization, OrganizationOnboarding $state, PlanCatalogue $plans): void
    {
        $this->form->fillFrom($organization);
        $this->annualVolume = $state->declared_annual_volume_kobo !== null
            ? number_format(intdiv($state->declared_annual_volume_kobo->kobo, 100))
            : '';
        $this->tier = $state->requested_tier?->value;
        $featured = collect($plans->plans())->first(fn (Plan $plan): bool => $plan->featured);
        $this->plan = $organization->signup_plan_key ?? $featured->key ?? '';
    }

    /** Runs a wizard change; a stale tab gets the current step instead of an error. */
    private function attempt(callable $change): bool
    {
        try {
            $change();
        } catch (StepOutOfOrder) {
            $this->notice = 'This step was already saved, perhaps in another tab. Here is where you are now.';

            return false;
        }

        $this->notice = null;
        $this->resetValidation();

        return true;
    }

    private function authorizeStep(OnboardingStep $step): void
    {
        $organization = Organization::query()->whereKey($this->organizationId)->firstOrFail();

        $this->authorize('continueSignup', [$organization, $step]);
    }

    private function state(OnboardingWizard $wizard): OrganizationOnboarding
    {
        $state = $wizard->state();
        abort_if($state === null || $state->isComplete(), 404);

        return $state;
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
