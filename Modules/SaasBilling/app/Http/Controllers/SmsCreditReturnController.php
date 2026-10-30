<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Http\Controllers;

use App\Models\SmsCreditEntry;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\SaasBilling\Enums\PaymentOutcome;
use Modules\SaasBilling\Paystack\PaystackUnavailable;
use Modules\SaasBilling\SmsCredits\SmsCreditPurchases;

/**
 * GET /o/{organization}/settings/sms-credits/paid?reference=…: where
 * Paystack's checkout sends the buyer back (P05-03). It settles the
 * payment by asking Paystack (SmsCreditPurchases::settle: verify by fetch;
 * the query string is never trusted) and goes back to Settings → SMS
 * credits with what happened. Only the organization's own payments are
 * found (organization scope); members who may see the credits only.
 */
final class SmsCreditReturnController
{
    public function __invoke(Request $request, OrganizationContext $context, SmsCreditPurchases $purchases): RedirectResponse
    {
        Gate::authorize('viewAny', SmsCreditEntry::class);

        $reference = $request->query('reference', $request->query('trxref'));
        $reference = is_string($reference) && preg_match('/^PINV-[A-Za-z0-9-]{6,60}$/', $reference) === 1 ? $reference : null;

        try {
            $outcome = $reference !== null ? $purchases->settle($reference) : PaymentOutcome::Ignored;
        } catch (PaystackUnavailable) {
            $outcome = null;
        }

        $message = match ($outcome) {
            PaymentOutcome::Confirmed, PaymentOutcome::Duplicate => ['success', __('Payment received: the credits are in your balance.')],
            PaymentOutcome::Held => ['warning', __('Paystack took a payment that does not match the invoice, so no credits were added yet. GoProperty will look into it and contact you.')],
            PaymentOutcome::NotPaid => ['warning', __('Paystack has not confirmed the payment. If you paid, the credits arrive as soon as it does.')],
            null => ['warning', __('Paystack could not be asked about the payment just now. If you paid, the credits arrive as soon as it confirms it.')],
            default => ['warning', __('That payment was not found.')],
        };

        return redirect()
            ->route('workspace.settings.sms-credits', ['organization' => $context->get()->slug])
            ->with('sms_credits_status', $message);
    }
}
