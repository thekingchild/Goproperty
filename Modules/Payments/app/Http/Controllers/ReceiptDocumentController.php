<?php

declare(strict_types=1);

namespace Modules\Payments\Http\Controllers;

use App\Documents\DocumentRenderer;
use App\Media\MediaUrls;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Modules\Payments\Models\Receipt;
use Modules\Payments\Receipts\Receipts;

/**
 * A receipt as printed (P03-10b; BRANDING_DOCUMENTS.md § 3, § 5), for the
 * members who may see it (ReceiptPolicy::viewDocument: it shows the
 * tenant's phone and email, so never while staff impersonate):
 *
 * - document(): the responsive web page, built from what was kept at issue
 *   (with the VOID watermark once voided);
 * - pdf(): the PDF as issued — the kept evidence file, through a
 *   short-lived signed link (App\Media\MediaUrls); rendered now, once,
 *   when the `pdf` queue has not got to it yet;
 * - voidPdf(): a voided receipt's VOID copy, likewise; 404 before a void.
 *
 * {receipt} resolves inside the organization only (the `organization`
 * middleware runs first), so another organization's receipt answers 404.
 */
final class ReceiptDocumentController
{
    public function document(Organization $organization, Receipt $receipt, Receipts $receipts, DocumentRenderer $renderer): Response
    {
        Gate::authorize('viewDocument', $receipt);

        return response($renderer->web($receipts->document($receipt, void: $receipt->isVoided())))
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function pdf(Organization $organization, Receipt $receipt, Receipts $receipts, MediaUrls $urls): RedirectResponse
    {
        Gate::authorize('viewDocument', $receipt);

        return redirect()->away($urls->temporary($receipts->pdf($receipt)));
    }

    public function voidPdf(Organization $organization, Receipt $receipt, Receipts $receipts, MediaUrls $urls): RedirectResponse
    {
        Gate::authorize('viewDocument', $receipt);
        abort_unless($receipt->isVoided(), 404);

        return redirect()->away($urls->temporary($receipts->voidPdf($receipt)));
    }
}
