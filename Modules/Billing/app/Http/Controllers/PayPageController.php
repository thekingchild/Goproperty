<?php

declare(strict_types=1);

namespace Modules\Billing\Http\Controllers;

use App\Http\Middleware\SignInPageHeaders;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Billing\Bills\PaymentReferences;
use Modules\Billing\Pay\PayPages;

/**
 * The branded public pay page, /pay/{reference} (P03-05c;
 * BRANDING_DOCUMENTS.md § 1 "Pay page": agency branded, no login, bank
 * details and the online-payment button if available;
 * ONBOARDING_COMPLIANCE.md § 4.1: "Put this in your transfer narration").
 *
 * No sign-in and no policy: the payment reference is the only key (35
 * random bits, Bills\PaymentReferences), the page is rate-limited per IP
 * (`throttle:30,1,pay`) and shows the bill's agency, number, dates, lines
 * and money, the building and unit, and how to pay — never the tenant's
 * name or contact details. A reference typed with spaces, dashes, lower
 * case or O/I/L is redirected (301) to its one address; an unknown or
 * malformed reference is a plain 404. A void bill says so ("do not pay
 * it") and a paid one says it is paid, both without bank details.
 */
final class PayPageController
{
    public function __invoke(Request $request, string $reference, PayPages $pages): Response|RedirectResponse
    {
        $normal = PaymentReferences::normalize($reference);

        abort_if($normal === null, 404);

        if ($normal !== $reference) {
            return redirect()->route('pay.show', ['reference' => $normal], 301);
        }

        $page = $pages->find($normal);

        abort_if($page === null, 404);

        // The sign-in pages' Content-Security-Policy: no scripts, inline styles and data: images only.
        $request->attributes->set(SignInPageHeaders::ATTRIBUTE, true);

        $response = response()->view('billing::pay.show', ['page' => $page]);
        // The amount owed changes as payments land: never cached; not for search engines.
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
