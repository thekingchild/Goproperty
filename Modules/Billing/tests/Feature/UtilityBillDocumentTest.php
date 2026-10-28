<?php

declare(strict_types=1);

use App\Documents\DocumentLayout;
use App\Documents\DocumentRenderer;
use App\Documents\Invoice\InvoiceDocument;
use App\Documents\RenderTarget;
use App\Documents\Verification\VerificationCodes;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Storage;
use Modules\Billing\Documents\Invoices;
use Modules\Billing\Documents\UtilityBillDocument;
use Modules\Billing\Jobs\RenderInvoice;
use Modules\Billing\Models\Bill;
use Tests\Support\DocumentCssLint;
use Tests\Support\NarrowScreen;
use Tests\Support\PdfVisualDiff;

require_once __DIR__.'/invoice-helpers.php';
require_once __DIR__.'/utility-bill-helpers.php';

/*
 * P03-06b: the utility bill as printed (BRANDING_DOCUMENTS.md § 4 "Utility
 * bill", § 5 "How it is built (P03-06b)"): a utility bill's invoice in the
 * organization's layout, with the utility and, for a metered utility, the
 * readings table (previous, current, consumption, rate, amount). Rendered
 * once on the pdf queue and kept, like every invoice. Acceptance: the
 * utility bill PDF renders with both drivers — dompdf always; the Chrome
 * half is skipped only where Gotenberg is unreachable (it runs in CI,
 * P03 decision 6).
 */

beforeEach(function () {
    Storage::fake('media');
    holdInvoicePdfs();
    seedOrganizationRoles();
    withoutAccountNameLookups();
    $this->travelTo('2026-12-20 10:00:00');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $this->owner = joinAs(User::factory()->create(), $this->acme);
    $this->palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court', 'address' => '22 Fola Osibo Road, Lekki Phase 1', 'state' => 'Lagos']);
    $this->flat = unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 3B']);
    $this->kemi = tenantIn($this->acme, ['first_name' => 'Kemi', 'last_name' => 'Adebayo', 'phone' => '+2348031112222', 'email' => 'kemi.adebayo@example.com']);
    $this->account = addBankAccount($this->acme, $this->owner, '0123456789', 'Adeyemi Realty Ltd');
    $this->electricity = electricityIn($this->acme, $this->owner);
    $this->security = utilityTypeIn($this->acme, $this->owner);
});

afterEach(fn () => billsFreshRequest());

/**
 * January's electricity of Flat 3B (Kemi's lease): read on 15 January
 * (1,100: 100 kWh, ₦25,000) and 31 January (1,234.567: 134.567 kWh,
 * ₦33,641.75), billed on 1 February.
 */
function meteredUtilityBill(object $test, string $layout = 'modern'): Bill
{
    invoiceBranding($test->acme, $test->owner, ['layout' => $layout]);
    monthlyLease($test->acme, $test->owner, $test->flat, $test->kemi);
    $meter = utilityOn($test->acme, $test->owner, $test->flat, $test->electricity, ['startingReading' => '1,000']);
    $test->travelTo('2027-01-15 18:00:00');
    readMeter($test->acme, $test->owner, $meter, '2027-01-15', '1100');
    $test->travelTo('2027-01-31 18:00:00');
    readMeter($test->acme, $test->owner, $meter, '2027-01-31', '1234.567');
    onUtilityBillingDay('2027-02-01');
    runBillGeneration();

    return utilityBillsOf($test->acme)->sole();
}

/** January's security levy of Flat 3B (₦25,000, monthly), billed on 25 December. */
function flatUtilityBill(object $test, string $layout = 'modern'): Bill
{
    invoiceBranding($test->acme, $test->owner, ['layout' => $layout]);
    monthlyLease($test->acme, $test->owner, $test->flat, $test->kemi);
    utilityOn($test->acme, $test->owner, $test->flat, $test->security);
    onUtilityBillingDay('2026-12-25');
    runBillGeneration();

    return utilityBillsOf($test->acme)->sole();
}

function utilityBillOfKind(object $test, string $kind, string $layout = 'modern'): Bill
{
    return $kind === 'metered' ? meteredUtilityBill($test, $layout) : flatUtilityBill($test, $layout);
}

/** @return list<string> what the bill of that kind prints besides the invoice's parts */
function utilityBillText(string $kind): array
{
    return $kind === 'metered'
        ? ['Electricity', 'Metered, per kWh', '1,100', '1,234.567', '134.567', '25,000', '33,641.75', '58,641.75']
        : ['Security levy', 'Flat charge, monthly', '25,000'];
}

describe('acceptance: the utility bill PDF renders with both drivers', function () {
    it('prints on one A4 page with dompdf, in the organization\'s layout', function (DocumentLayout $layout, string $kind) {
        $bill = utilityBillOfKind($this, $kind, $layout->value);
        $document = inOrganization($this->acme, fn () => app(Invoices::class)->document(storedBill($this->acme, $bill)));
        $pdf = app(DocumentRenderer::class)->pdf($document, 'dompdf');

        requirePdfTools();
        expect($document)->toBeInstanceOf(UtilityBillDocument::class)
            ->and($document->view())->toBe('billing::documents.utility.'.$layout->value)
            ->and(PdfVisualDiff::rasterise($pdf))->toHaveCount(1)
            ->and(PdfVisualDiff::text($pdf))->toContain('UTILITY BILL', $bill->number, 'Kemi Adebayo', 'Flat 3B', '0123456789', $bill->payment_reference, 'Page 1 of 1', ...utilityBillText($kind));
    })->with([DocumentLayout::Modern, DocumentLayout::Classic, DocumentLayout::Compact])->with(['metered', 'flat']);

    it('prints the same with Chrome (Gotenberg) as with dompdf, within the threshold', function (DocumentLayout $layout, string $kind) {
        requirePdfTools();
        requireGotenberg();
        $bill = utilityBillOfKind($this, $kind, $layout->value);
        $document = inOrganization($this->acme, fn () => app(Invoices::class)->document(storedBill($this->acme, $bill)));
        $renderer = app(DocumentRenderer::class);

        $diff = PdfVisualDiff::compare($renderer->pdf($document, 'dompdf'), $chrome = $renderer->pdf($document, 'chrome'));

        expect($diff['pages'])->toBe([1, 1])
            // Chrome's text layer splits letter-spaced titles ("UTIL IT Y BIL L"): the rest is checked.
            ->and(PdfVisualDiff::text($chrome))->toContain($bill->number, VerificationCodes::format($bill->verification_code), 'Page 1 of 1', ...utilityBillText($kind))
            ->and($diff['score'])->toBeLessThan(PdfVisualDiff::THRESHOLD);
    })->with([DocumentLayout::Modern, DocumentLayout::Classic, DocumentLayout::Compact])->with(['metered', 'flat']);
});

it('shows the readings table on the web page: previous, current, consumption, rate and amount of each reading', function () {
    $bill = meteredUtilityBill($this);
    $this->travelTo('2027-02-01 09:00:00');
    $html = invoicePage($this->owner, $bill)->assertOk()->getContent();

    expect($html)->toContain('UTILITY BILL', 'Read on', 'Previous', 'Current', 'Used (kWh)', 'Rate', 'Amount')
        ->and(preg_replace('/\s+/', ' ', strip_tags((string) $html)))->toContain(
            // The date, the values under it for phones, then the columns.
            '15/01/20271,000 to 1,100 1,000 1,100 100 ₦250 ₦25,000',
            '31/01/20271,100 to 1,234.567 1,100 1,234.567 134.567 ₦250 ₦33,641.75',
            'Metered, per kWh',
            '01/01/2027 – 31/01/2027',
        );
});

it('prints a flat utility\'s charge as a line, without a readings table', function () {
    $bill = flatUtilityBill($this);
    $html = (string) invoicePage($this->owner, $bill)->assertOk()->getContent();

    expect($html)->toContain('UTILITY BILL', 'Security levy', 'Flat charge, monthly', '₦25,000')
        ->not->toContain('Used (', 'class="items readings"');
});

it('keeps rent bills printing as invoices', function () {
    monthlyLease($this->acme, $this->owner, $this->flat, $this->kemi);
    onUtilityBillingDay('2026-12-25');
    runBillGeneration();
    $rent = billsOf($this->acme)->sole();

    expect(inOrganization($this->acme, fn () => app(Invoices::class)->document($rent)))->toBeInstanceOf(InvoiceDocument::class)
        ->and((string) invoicePage($this->owner, $rent)->assertOk()->getContent())->toContain('INVOICE')->not->toContain('UTILITY BILL');
});

it('renders the kept PDF once on the pdf queue, as a utility bill', function () {
    $bill = meteredUtilityBill($this);
    $counter = countingPdfDriver();

    (new RenderInvoice($this->acme->id, $bill->id))->handle(app(OrganizationContext::class), app(Invoices::class));
    (new RenderInvoice($this->acme->id, $bill->id))->handle(app(OrganizationContext::class), app(Invoices::class));
    $kept = keptInvoice($this->acme, $bill);

    requirePdfTools();
    expect($counter->renders)->toBe(1)
        ->and($kept)->not->toBeNull()
        ->and($kept?->getCustomProperty('document_key'))->toBe(Invoices::DOCUMENT_KEY)
        ->and(storedBill($this->acme, $bill)->document_media_id)->toBe($kept?->id)
        ->and(PdfVisualDiff::text(followInvoice(invoiceDownload($this->owner, $bill))))->toContain('UTILITY BILL', '134.567');
});

it('uses only table layouts and simple CSS as rendered, for every layout and target', function (DocumentLayout $layout, RenderTarget $target) {
    $bill = meteredUtilityBill($this, $layout->value);
    $document = inOrganization($this->acme, fn () => app(Invoices::class)->document(storedBill($this->acme, $bill)));

    expect(DocumentCssLint::problems(app(DocumentRenderer::class)->html($document, $target)))->toBe([]);
})->with([DocumentLayout::Modern, DocumentLayout::Classic, DocumentLayout::Compact])->with(RenderTarget::cases());

it('lays out on a 360 px screen without sideways scrolling, in real Chrome', function (DocumentLayout $layout) {
    $gotenberg = requireGotenberg();
    $bill = meteredUtilityBill($this, $layout->value);
    $html = inOrganization($this->acme, fn () => app(DocumentRenderer::class)->web(app(Invoices::class)->document(storedBill($this->acme, $bill))));

    $screen = NarrowScreen::measure($gotenberg, $html, 360);

    expect($screen['viewport'])->toBe(360)
        ->and($screen['overflow'])->toBe(0);
})->with([DocumentLayout::Modern, DocumentLayout::Classic, DocumentLayout::Compact]);
