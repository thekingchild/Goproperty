<?php

declare(strict_types=1);

namespace App\Marketing;

use App\Enums\BillingCycle;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;

/**
 * The plan and billing cycle a visitor picked on the pricing page (P06-11),
 * carried through signup in the session: the account step shows it, and the
 * wizard's plan step starts with it selected, so the trial starts on what
 * was chosen (ONBOARDING_COMPLIANCE.md § 2 step 4; no card, no payment).
 *
 * Only a plan offered to new signups and not priced "from" (Enterprise is
 * "Talk to us") is kept. The session survives the verification link opened
 * in the same browser (VerifyEmailController regenerates, never flushes);
 * opened in another browser, the wizard proposes its usual plan.
 */
final readonly class SignupPlanChoice
{
    private const string SESSION_PLAN = 'signup.plan';

    private const string SESSION_CYCLE = 'signup.cycle';

    public function __construct(private PlanCatalogue $plans) {}

    /** Keeps `?plan=…&cycle=…` from a pricing-page link; anything else leaves the choice as it was. */
    public function remember(Request $request): void
    {
        $plan = $this->choosable($request->query('plan'));

        if ($plan === null || ! $request->hasSession()) {
            return;
        }

        $request->session()->put(self::SESSION_PLAN, $plan->key);
        $request->session()->put(self::SESSION_CYCLE, self::cycleFrom($request->query('cycle'))->value);
    }

    /** The plan chosen in this browser, while it is still offered. */
    public function plan(Session $session): ?Plan
    {
        return $this->choosable($session->get(self::SESSION_PLAN));
    }

    public function cycle(Session $session): BillingCycle
    {
        return self::cycleFrom($session->get(self::SESSION_CYCLE));
    }

    /** Once the plan step is saved, the choice has done its job. */
    public function forget(Session $session): void
    {
        $session->forget([self::SESSION_PLAN, self::SESSION_CYCLE]);
    }

    /** "annual" (or "yearly", "year") is yearly; anything else monthly. */
    public static function cycleFrom(mixed $value): BillingCycle
    {
        return in_array($value, [BillingCycle::Annual->value, 'yearly', 'year'], true) ? BillingCycle::Annual : BillingCycle::Monthly;
    }

    private function choosable(mixed $key): ?Plan
    {
        if (! is_string($key) || $key === '') {
            return null;
        }

        foreach ($this->plans->plans() as $plan) {
            if ($plan->key === $key && ! $plan->custom) {
                return $plan;
            }
        }

        return null;
    }
}
