<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Marketing\Plan;
use App\Marketing\PlanCatalogue;
use App\Models\Subscription;
use App\Models\User;
use App\Plans\Feature;
use App\Plans\Features;
use App\Subscriptions\SubscriptionChangeRefused;
use App\Subscriptions\SubscriptionOverview;
use App\Subscriptions\SubscriptionService;
use App\Tenancy\BlockedWhileImpersonating;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Settings → Subscription, /o/{organization}/settings/subscription
 * (P06-01b; BILLING_PAYMENTS.md § 1–2): the plan and its state (trial days
 * left), today's monthly bill for the units counted, a scheduled change of
 * the plan's terms (the 30 days' notice), what the plan includes with the
 * staff users used, and the other plans at the organization's units.
 *
 * Seeing it: SubscriptionPolicy::viewAny (owners, administrators). Moving
 * to another plan: owners (`organization.billing`), never while GoProperty
 * staff impersonate nor while the organization is read-only; at once,
 * through SubscriptionService::changePlan(), which refuses a plan whose
 * limits the organization is over.
 */
#[Layout('layouts::app')]
#[Title('Subscription')]
final class SubscriptionPage extends Component
{
    #[Locked]
    public int $organizationId;

    public ?string $notice = null;

    public function boot(OrganizationContext $context): void
    {
        abort_unless($context->has(), 404);
    }

    public function hydrate(OrganizationContext $context): void
    {
        abort_unless($context->id() === $this->organizationId, 404);
    }

    public function mount(OrganizationContext $context): void
    {
        $this->organizationId = $context->id();
        $this->authorize('viewAny', Subscription::class);
    }

    #[BlockedWhileImpersonating]
    public function changePlan(string $key, PlanCatalogue $plans, SubscriptionService $subscriptions): void
    {
        $this->authorize('changePlan', Subscription::class);
        $this->resetErrorBag('plan');
        $this->notice = null;
        $plan = $plans->find($key);

        if (! $plan instanceof Plan || ! $plan->offered) {
            $this->addError('plan', __('That plan is not offered.'));

            return;
        }

        try {
            $subscriptions->changePlan($this->user(), $plan);
        } catch (SubscriptionChangeRefused $refused) {
            $this->addError('plan', $refused->getMessage());

            return;
        }

        $this->notice = __('You are now on :plan.', ['plan' => $plan->name]);
    }

    public function render(SubscriptionOverview $overview, PlanCatalogue $plans, Features $features): View
    {
        $plan = $overview->plan();
        $units = $overview->units();

        return view('livewire.settings.subscription-page', [
            'subscription' => $overview->subscription(),
            'plan' => $plan,
            'units' => $units,
            'monthly' => $plan?->priceFor($units),
            'vat' => $plans->vatPercent(),
            'change' => $overview->scheduledChange(),
            'features' => array_map(static fn (Feature $feature): array => [
                'label' => $feature->label(),
                'value' => $features->value($feature)->describe($feature),
                'key' => $feature->value,
            ], Feature::cases()),
            'staffUsed' => $overview->staffUsed(),
            'staffLimit' => $overview->staffLimit(),
            'choices' => array_values(array_filter($plans->plans(), static fn (Plan $choice): bool => ! $choice->custom)),
            'canChange' => $this->user()->can('changePlan', Subscription::class),
        ]);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
