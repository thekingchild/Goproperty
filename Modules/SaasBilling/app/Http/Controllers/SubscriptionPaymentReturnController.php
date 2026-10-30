<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Http\Controllers;

use App\Models\Subscription;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\SaasBilling\Enums\PaymentOutcome;
use Modules\SaasBilling\Payments\PlatformPaymentSettlement;
use Modules\SaasBilling\Paystack\PaystackUnavailable;

/**
 * GET /o/{organization}/settings/subscription/paid?reference=…: where
 * Paystack's checkout sends an owner back after paying a subscription
 * invoice (P06-03). It settles the payment by asking Paystack
 * (PlatformPaymentSettlement: verify by fetch; the query string is never
 * trusted) and goes back to Settings → Subscription with what happened.
 * Only the organization's own payments are found (organization scope);
 * members who may see the subscription only. A read for the guard: it
 * works while the organization is suspended (paying is allowed then).
 */
final class SubscriptionPaymentReturnController
{
    public function __invoke(Request $request, OrganizationContext $context, PlatformPaymentSettlement $settlement): RedirectResponse
    {
        Gate::authorize('viewAny', Subscription::class);

        $reference = $request->query('reference', $request->query('trxref'));
        $reference = is_string($reference) && preg_match('/^PINV-[A-Za-z0-9-]{6,60}$/', $reference) === 1 ? $reference : null;

        try {
            $outcome = $reference !== null ? $settlement->settle($reference) : PaymentOutcome::Ignored;
        } catch (PaystackUnavailable) {
            $outcome = null;
        }

        $message = match ($outcome) {
            PaymentOutcome::Confirmed, PaymentOutcome::Duplicate => ['success', __('Payment received: the invoice is paid. Thank you.')],
            PaymentOutcome::Held => ['warning', __('Paystack took a payment that does not match the invoice, so it is not marked paid yet. GoProperty will look into it and contact you.')],
            PaymentOutcome::NotPaid => ['warning', __('Paystack has not confirmed the payment. If you paid, the invoice is marked paid as soon as it does.')],
            null => ['warning', __('Paystack could not be asked about the payment just now. If you paid, the invoice is marked paid as soon as it confirms it.')],
            default => ['warning', __('That payment was not found.')],
        };

        return redirect()
            ->route('workspace.settings.subscription', ['organization' => $context->get()->slug])
            ->with('subscription_status', $message);
    }
}
