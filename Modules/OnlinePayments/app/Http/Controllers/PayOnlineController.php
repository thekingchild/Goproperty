<?php

declare(strict_types=1);

namespace Modules\OnlinePayments\Http\Controllers;

use App\Branding\Branding;
use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Modules\Billing\Contracts\BillBalance;
use Modules\Billing\Contracts\BillLedger;
use Modules\OnlinePayments\Jobs\SettlePaystackTransaction;
use Modules\OnlinePayments\Models\PaystackTransaction;
use Modules\OnlinePayments\Transactions\OnlinePaymentRefused;
use Modules\OnlinePayments\Transactions\PaystackTransactions;

/**
 * Paying a bill online through the organization's own Paystack account
 * (ONBOARDING_COMPLIANCE.md § 4.2), from the bill's pay page:
 *
 * - GET /pay/{reference}/online (signed, PayOnline\PaystackOption::link()):
 *   the amount and the payer's email (Paystack sends its receipt there);
 * - POST the same signed address: the transaction is started with the
 *   organization's key and the payer is sent (303) to Paystack's checkout;
 * - GET /pay/{reference}/online/return: Paystack sends the payer back here
 *   with its `reference`; the transaction is settled on the queue
 *   (Jobs\SettlePaystackTransaction) and the payer lands on /pay/{reference}.
 *
 * No sign-in, like the pay page: the signed address is the key (it names
 * the organization, so the bill is found through Billing's BillLedger
 * inside that organization), a bad or expired signature is a plain 404.
 * Never the tenant's details. Rate-limited per IP (routes). A bill that
 * can no longer be paid online (paid, void, above the cap, Paystack
 * disconnected) sends the payer back to its pay page.
 */
final class PayOnlineController
{
    public function show(Request $request, string $reference): Response|RedirectResponse
    {
        return $this->forBill($request, $reference, function (Organization $organization, BillBalance $bill) use ($request): Response {
            $response = response()->view('onlinepayments::pay.online', [
                'brand' => Branding::fromOrganization($organization),
                'bill' => $bill,
                'action' => $request->fullUrl(),
                'back' => route('pay.show', ['reference' => $bill->paymentReference]),
            ]);

            return $this->secured($response);
        });
    }

    public function start(Request $request, string $reference): Response|RedirectResponse
    {
        $email = trim((string) $request->input('email', ''));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 150) {
            return back()->withErrors(['email' => __('Enter your email address: Paystack sends your receipt there.')])->withInput();
        }

        return $this->forBill($request, $reference, function (Organization $organization, BillBalance $bill) use ($email): RedirectResponse {
            $back = route('pay.show', ['reference' => $bill->paymentReference]);

            try {
                $checkout = $this->transactions()->start($bill, $email, route('online-payments.return', ['reference' => $bill->paymentReference]), $back);
            } catch (OnlinePaymentRefused $refused) {
                return back()->withErrors(['email' => $refused->getMessage()])->withInput();
            }

            return redirect()->away($checkout, 303);
        });
    }

    public function return(Request $request, string $reference): RedirectResponse
    {
        $transaction = $request->query('reference', $request->query('trxref'));
        $back = redirect()->route('pay.show', ['reference' => $reference], 303);

        if (! is_string($transaction) || preg_match('/^GPO-[0-9A-F]{20}$/', $transaction) !== 1) {
            return $back;
        }

        // The transaction finds its organization (one unscoped read of this module's own table, the P01-04 pattern).
        $organizationId = DB::table('paystack_transactions')->where('reference', $transaction)->value('organization_id');
        $organization = is_numeric($organizationId) ? Organization::query()->find((int) $organizationId) : null;

        if (! $organization instanceof Organization) {
            return $back;
        }

        $this->context()->run($organization, function () use ($organization, $transaction, $reference): void {
            $record = PaystackTransaction::query()->where('reference', $transaction)->first();
            $bill = $record instanceof PaystackTransaction ? $this->ledger()->balance($record->bill_id) : null;

            if ($bill !== null && $bill->paymentReference === $reference && ! $record->status->isFinal()) {
                SettlePaystackTransaction::dispatch($organization->id, $transaction);
            }
        });

        return $back;
    }

    /**
     * Runs $then for the bill of a valid signed address, inside its
     * organization; a bill no longer payable online goes back to its pay page.
     *
     * @param  Closure(Organization, BillBalance): (Response|RedirectResponse)  $then
     */
    private function forBill(Request $request, string $reference, Closure $then): Response|RedirectResponse
    {
        abort_unless(URL::hasValidSignature($request), 404);

        $organizationId = $request->query('organization');
        $organization = is_string($organizationId) && ctype_digit($organizationId) ? Organization::query()->find((int) $organizationId) : null;

        abort_unless($organization instanceof Organization, 404);

        return $this->context()->run($organization, function (Organization $organization) use ($reference, $then): Response|RedirectResponse {
            $bill = $this->ledger()->findByReference($reference);

            abort_if($bill === null || $bill->paymentReference !== $reference, 404);

            if (! $this->transactions()->offers($bill)) {
                return redirect()->route('pay.show', ['reference' => $bill->paymentReference], 303);
            }

            return $then($organization, $bill);
        });
    }

    // Resolved per request: the router keeps controller instances, and the organization context is per request.
    private function context(): OrganizationContext
    {
        return app(OrganizationContext::class);
    }

    private function ledger(): BillLedger
    {
        return app(BillLedger::class);
    }

    private function transactions(): PaystackTransactions
    {
        return app(PaystackTransactions::class);
    }

    /** The pay page's policy, with Paystack's checkout allowed as the form's destination. */
    private function secured(Response $response): Response
    {
        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            "script-src 'none'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self' https://checkout.paystack.com",
            "frame-ancestors 'none'",
        ]));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
