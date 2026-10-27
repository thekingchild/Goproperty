<?php

declare(strict_types=1);

namespace Modules\Billing\Http\Controllers;

use App\Documents\DocumentRenderer;
use App\Media\MediaUrls;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Modules\Billing\Documents\Invoices;
use Modules\Billing\Models\Bill;

/**
 * A bill's invoice from the bill page (P03-05c; BRANDING_DOCUMENTS.md § 5:
 * "every document is also viewable as a responsive web page; the PDF is a
 * download"). Both check BillPolicy::viewInvoice first; {bill} resolves
 * inside the organization only (the `organization` middleware runs
 * first), so another organization's bill answers 404, as does a bill
 * outside a narrowed agent's buildings.
 *
 * - show: /o/{organization}/bills/{bill}/invoice, the invoice as a web
 *   page (branding and parties as issued, status and payments now).
 * - download: /o/{organization}/bills/{bill}/invoice.pdf, a short-lived
 *   signed link (App\Media\MediaUrls) to the kept PDF; it is rendered now,
 *   once, only when the `pdf` queue has not got to it yet.
 */
final class InvoiceController
{
    public function show(Organization $organization, Bill $bill, Invoices $invoices, DocumentRenderer $renderer): Response
    {
        Gate::authorize('viewInvoice', $bill);

        return response($renderer->web($invoices->document($bill)), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    public function download(Organization $organization, Bill $bill, Invoices $invoices, MediaUrls $urls): RedirectResponse
    {
        Gate::authorize('viewInvoice', $bill);

        return redirect()->away($urls->temporary($invoices->pdf($bill)));
    }
}
