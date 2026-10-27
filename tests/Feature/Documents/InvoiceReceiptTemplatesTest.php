<?php

declare(strict_types=1);

use App\Documents\BrandingPreview;
use App\Documents\Document;
use App\Documents\DocumentLayout;
use App\Documents\DocumentRenderer;
use App\Documents\Invoice\InvoiceDocument;
use App\Documents\Invoice\InvoiceLine;
use App\Documents\Invoice\InvoiceStatus;
use App\Documents\Parts\DocumentPeriod;
use App\Documents\Parts\DocumentProperty;
use App\Documents\Parts\DocumentTenant;
use App\Documents\PdfPages;
use App\Documents\Receipt\ReceiptDocument;
use App\Documents\Receipt\ReceiptPayment;
use App\Documents\RenderTarget;
use App\Documents\SampleDocuments;
use App\Pdf\PdfManager;
use App\Support\Money;
use App\Theme\Color;
use Carbon\CarbonImmutable;
use Tests\Support\DocumentCssLint;
use Tests\Support\NarrowScreen;
use Tests\Support\PdfVisualDiff;

require_once __DIR__.'/../../Support/documents.php';

/*
 * P03-05b: the invoice and receipt templates (BRANDING_DOCUMENTS.md § 2, § 3,
 * § 5; designs docs/design/templates/invoice.html and receipt.html) in the
 * three layouts, built from InvoiceDocument and ReceiptDocument.
 *
 * Acceptance (IMPLEMENTATION_PLAN.md § 3 P03-05): invoice and receipt render
 * identically (visual diff) with dompdf and Chrome, on one A4 page for up to
 * 8 line items, in every layout. dompdf is always tested; the Chrome half
 * needs Gotenberg, which CI runs as a service (decision 6) and which is
 * skipped only on a machine without it.
 *
 * The documents are the fullest the one-page promise covers (fullInvoice(),
 * fullReceipt() in tests/Support/documents.php): 8 lines each with a note,
 * credit and part payment, bank and online payment, logo, signature and
 * stamp, the longest invoice notes (600) and receipt footer (300) allowed.
 */

/** @return array<string, array{0: DocumentLayout, 1: string}> layout × document */
function layoutsAndKinds(): array
{
    $cases = [];

    foreach (DocumentLayout::cases() as $layout) {
        foreach (['invoice', 'receipt'] as $kind) {
            $cases[$layout->value.' '.$kind] = [$layout, $kind];
        }
    }

    return $cases;
}

function fullDocument(DocumentLayout $layout, string $kind, int $lines = 8, bool $void = false): Document
{
    $brand = fullBrand($layout);

    return $kind === 'invoice'
        ? fullInvoice($brand, $lines, $void ? InvoiceStatus::Void : InvoiceStatus::PartlyPaid, $void ? 'Raised in error: the lease ended in October.' : null)
        : fullReceipt($brand, $lines, $void);
}

describe('acceptance: invoice and receipt render identically with dompdf and Chrome, on one A4 page for up to 8 lines', function () {
    it('prints on one A4 page with dompdf, with every line and the footer', function (DocumentLayout $layout, string $kind, int $lines) {
        $pdf = app(DocumentRenderer::class)->pdf(fullDocument($layout, $kind, $lines), 'dompdf');

        expect(PdfPages::count($pdf))->toBe(1)
            ->and($pdf)->toContain('/MediaBox [0.000 0.000 595.280 841.890]')
            ->and($pdf)->toMatch('#/BaseFont /[A-Z]{6}\+Inter-Regular#');

        requirePdfTools();
        $text = PdfVisualDiff::text($pdf);

        expect($text)->toContain('Page 1 of 1', 'Generated with GoProperty', 'Adeyemi Realty');

        if ($kind === 'invoice') {
            expect($text)->toContain('INVOICE', 'INV-ADR-2027-000142', 'PARTLY PAID', 'GP-7KQ2M', '7KQ2M-X9P4D', 'Guaranty Trust Bank', 'AMOUNT DUE')
                ->and($text)->toContain($lines === 8 ? 'Tenancy agreement, first year only' : 'Annual rent, renewal year 3');
        } else {
            expect($text)->toContain('OFFICIAL RECEIPT', 'RCT-ADR-2026-000318', 'PAID', 'R8X4P-2HC7N', 'naira only', 'Guaranty Trust Bank ···6789')
                ->and($text)->toContain('INV-ADR-2027-'.str_pad((string) (140 + $lines), 6, '0', STR_PAD_LEFT));
        }
    })->with(layoutsAndKinds())->with(['1 line' => 1, '8 lines' => 8]);

    it('prints the same with Chrome (Gotenberg) as with dompdf, within the threshold, on one page', function (DocumentLayout $layout, string $kind, bool $void) {
        requirePdfTools();
        requireGotenberg();
        $document = fullDocument($layout, $kind, 8, $void);
        $renderer = app(DocumentRenderer::class);

        $dompdf = $renderer->pdf($document, 'dompdf');
        $chrome = $renderer->pdf($document, 'chrome');
        $diff = PdfVisualDiff::compare($dompdf, $chrome);

        expect($diff['pages'])->toBe([1, 1])
            ->and(PdfVisualDiff::text($chrome))->toContain('Page 1 of 1', $kind === 'invoice' ? '7KQ2M-X9P4D' : 'R8X4P-2HC7N')
            ->and($diff['score'])->toBeLessThan(PdfVisualDiff::THRESHOLD);
    })->with(layoutsAndKinds())->with(['issued' => false, 'voided' => true]);

    it('prints a voided invoice and receipt on one page with dompdf too', function (DocumentLayout $layout, string $kind) {
        expect(PdfPages::count(app(DocumentRenderer::class)->pdf(fullDocument($layout, $kind, 8, true), 'dompdf')))->toBe(1);
    })->with(layoutsAndKinds());

    it('uses the organization\'s layout: one template per document and layout', function (DocumentLayout $layout, string $kind) {
        $document = fullDocument($layout, $kind);

        expect($document->view())->toBe("documents.{$kind}.{$layout->value}")
            ->and(view()->exists($document->view()))->toBeTrue()
            ->and(app(DocumentRenderer::class)->web($document))->toContain('class="layout-'.$layout->value.'"');
    })->with(layoutsAndKinds());
});

describe('VOID watermark', function () {
    it('marks a voided receipt and a void invoice with the watermark and the reason', function (DocumentLayout $layout, string $kind) {
        $renderer = app(DocumentRenderer::class);
        $void = fullDocument($layout, $kind, 4, true);
        $html = $renderer->web($void);

        expect($html)->toContain('class="void-watermark"', '>VOID</div>')
            ->and($html)->toContain($kind === 'invoice' ? 'Raised in error: the lease ended in October.' : 'Payment recorded twice; the bank statement shows one transfer.');

        if ($kind === 'receipt') {
            // VOID replaces the PAID stamp, and the notice replaces "valid without a signature".
            expect($html)->toContain('class="paid-stamp void-stamp">VOID', 'was voided on 15/12/2026, 09:10 WAT and is not proof of payment')
                ->and($html)->not->toContain('class="paid-stamp">PAID', 'valid without a signature', 'Keep this receipt safe');
        } else {
            expect($html)->toContain('badge-void">VOID', 'This invoice is void: nothing is due on it.')
                ->and($html)->not->toContain('Pay by bank transfer', 'Or pay online');
        }

        requirePdfTools();
        $dompdf = app(PdfManager::class)->renderer('dompdf');
        $printed = $renderer->html($void, RenderTarget::Dompdf);
        $withoutMark = str_replace('<div class="void-watermark" aria-hidden="true">VOID</div>', '', $printed);

        // The watermark is printed: the same page without it has less ink
        // across the middle, where the diagonal crosses.
        $ink = fn (string $html): int => array_sum(array_map(fn (array $row): int => array_sum(array_map(fn (int $grey): int => 255 - $grey, $row)), array_slice(PdfVisualDiff::rasterise($dompdf->render($html), 30)[0], 100, 180)));

        expect($withoutMark)->not->toBe($printed)
            ->and($ink($printed) - $ink($withoutMark))->toBeGreaterThan(20_000);
    })->with(layoutsAndKinds());

    it('leaves documents that are not void unmarked', function (DocumentLayout $layout, string $kind) {
        $html = app(DocumentRenderer::class)->web(fullDocument($layout, $kind));

        expect($html)->not->toContain('class="void-watermark"', 'void-notice');
    })->with(layoutsAndKinds());
});

describe('status badges (BRANDING_DOCUMENTS.md § 2 item 2)', function () {
    it('prints every status as its badge, readable on its colour', function (InvoiceStatus $status, string $label) {
        $html = app(DocumentRenderer::class)->web(fullInvoice(fullBrand(), 2, $status, $status === InvoiceStatus::Void ? 'Duplicate' : null));
        $colours = $status->badgeColours();

        expect($status->label())->toBe($label)
            ->and($html)->toContain('<span class="badge '.$status->badgeClass().'">'.$label.'</span>')
            ->and($html)->toContain('.'.$status->badgeClass().' { background: '.$colours['background'].'; color: '.$colours['text'].'; }')
            ->and(Color::contrast($colours['text'], $colours['background']))->toBeGreaterThanOrEqual(4.5);
    })->with([
        'draft' => [InvoiceStatus::Draft, 'DRAFT'],
        'unpaid' => [InvoiceStatus::Unpaid, 'UNPAID'],
        'partly paid' => [InvoiceStatus::PartlyPaid, 'PARTLY PAID'],
        'paid' => [InvoiceStatus::Paid, 'PAID'],
        'overdue' => [InvoiceStatus::Overdue, 'OVERDUE'],
        'void' => [InvoiceStatus::Void, 'VOID'],
    ]);

    it('takes a bill\'s status as stored (DATABASE_SCHEMA.md § 4 bills.status)', function () {
        expect(array_map(fn (string $status): InvoiceStatus => InvoiceStatus::from($status), ['unpaid', 'partially_paid', 'paid', 'overdue', 'void']))
            ->toBe([InvoiceStatus::Unpaid, InvoiceStatus::PartlyPaid, InvoiceStatus::Paid, InvoiceStatus::Overdue, InvoiceStatus::Void]);
    });

    it('asks for payment only while something is due', function (InvoiceStatus $status, bool $asks) {
        $html = app(DocumentRenderer::class)->web(fullInvoice(fullBrand(), 2, $status, $status === InvoiceStatus::Void ? 'Duplicate' : null));

        expect(str_contains($html, 'Pay by bank transfer'))->toBe($asks)
            ->and(str_contains($html, 'Or pay online'))->toBe($asks);
    })->with([
        'unpaid' => [InvoiceStatus::Unpaid, true],
        'partly paid' => [InvoiceStatus::PartlyPaid, true],
        'overdue' => [InvoiceStatus::Overdue, true],
        'paid' => [InvoiceStatus::Paid, false],
        'void' => [InvoiceStatus::Void, false],
        'draft' => [InvoiceStatus::Draft, false],
    ]);
});

describe('documents built from Money and the parts', function () {
    it('works out the invoice\'s subtotal and amount due in kobo', function () {
        $invoice = fullInvoice(fullBrand(), 3);

        // 3,500,000 + 3,124,874.50 + 2,749,749 − 34,000 credit − 500,000 paid.
        expect($invoice->subtotal()->equals(Money::kobo(937_462_350)))->toBeTrue()
            ->and($invoice->amountDue()->equals(Money::kobo(937_462_350 - 3_400_000 - 50_000_000)))->toBeTrue()
            ->and(app(DocumentRenderer::class)->web($invoice))->toContain('₦9,374,623.50', '− ₦34,000', '₦500,000', '₦8,840,623.50', 'Pay ₦8,840,623.50 online');
    });

    it('never asks for less than nothing, and nothing on a void invoice', function () {
        $paid = new InvoiceDocument(sampleBrand(), 'INV-1', CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'), InvoiceStatus::Paid, new DocumentTenant('A'), new DocumentProperty('B'), [new InvoiceLine('Rent', Money::kobo(10_000))], 'GP-1', '7KQ2MX9P4D', paidToDate: Money::kobo(12_000));

        expect($paid->amountDue()->isZero())->toBeTrue()
            ->and(fullInvoice(fullBrand(), 2, InvoiceStatus::Void, 'Duplicate')->amountDue()->isZero())->toBeTrue();
    });

    it('prints each line\'s own period, or the bill\'s', function () {
        $html = app(DocumentRenderer::class)->web(fullInvoice(fullBrand(), 8));

        expect($html)->toContain('01/01/2027 – 31/12/2027', '>Once<')
            ->and((new InvoiceLine('Rent', Money::kobo(1), period: new DocumentPeriod(CarbonImmutable::parse('2027-02-01'), CarbonImmutable::parse('2027-02-28'))))->periodLabel(null))->toBe('01/02/2027 – 28/02/2027');
    });

    it('keeps only the last four digits of the account a payment went into', function () {
        $payment = new ReceiptPayment('Bank transfer', CarbonImmutable::parse('2026-12-13'), 'Matched from bank statement', 'GTBank', '0123 456 789');
        $cash = new ReceiptPayment('Cash', CarbonImmutable::parse('2026-12-13'), 'Cash');

        expect($payment->paidInto())->toBe('GTBank ···6789')
            ->and(serialize($payment))->not->toContain('0123')
            ->and($cash->rows())->toBe(['Method' => 'Cash', 'Date paid' => '13/12/2026', 'Confirmed' => 'Cash'])
            ->and(app(DocumentRenderer::class)->web(fullReceipt(fullBrand())))->not->toContain('0123456789');
    });

    it('prints dates in Lagos time, whatever the server\'s timezone', function () {
        $receipt = fullReceipt(fullBrand());

        // 09:42 UTC is 10:42 WAT.
        expect($receipt->confirmedAtLabel())->toBe('14/12/2026, 10:42 WAT')
            ->and($receipt->sentSentence())->toBe('A copy was sent to the tenant by WhatsApp and email.');
    });

    it('refuses a document without lines, without a verification code, or half voided', function () {
        $brand = sampleBrand();
        $invoice = fn (array $lines, string $code) => new InvoiceDocument($brand, 'INV-1', CarbonImmutable::now(), CarbonImmutable::now(), InvoiceStatus::Unpaid, new DocumentTenant('A'), new DocumentProperty('B'), $lines, 'GP-1', $code);
        $receipt = fullReceipt($brand);

        expect(fn () => $invoice([], '7KQ2MX9P4D'))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $invoice([new InvoiceLine('Rent', Money::kobo(1))], 'nope'))->toThrow(InvalidArgumentException::class)
            ->and(fn () => new ReceiptDocument($brand, 'R', CarbonImmutable::now(), Money::kobo(1), $receipt->tenant, $receipt->property, $receipt->payment, [], Money::zero(), Money::zero(), 'X', CarbonImmutable::now(), 'R8X4P2HC7N', voidedAt: CarbonImmutable::now()))->toThrow(InvalidArgumentException::class);
    });

    it('names the downloaded PDF after the document', function () {
        expect(fullInvoice(fullBrand())->fileName())->toBe('INV-ADR-2027-000142.pdf')
            ->and(fullReceipt(fullBrand())->fileName())->toBe('RCT-ADR-2026-000318.pdf')
            ->and(fullReceipt(fullBrand(), 2, true)->fileName())->toBe('RCT-ADR-2026-000318-void.pdf');
    });

    it('escapes what organizations and tenants typed', function () {
        $brand = fullBrand(DocumentLayout::Modern, ['invoice_notes' => '<script>alert(1)</script>']);
        $invoice = fullInvoice($brand, 1);
        $tampered = new InvoiceDocument($brand, $invoice->number, $invoice->issuedOn, $invoice->dueOn, $invoice->status, new DocumentTenant('<b>Bold</b>'), $invoice->property, [new InvoiceLine('<img src=x onerror=alert(1)>', Money::kobo(1))], 'GP-1', '7KQ2MX9P4D');

        expect(app(DocumentRenderer::class)->web($tampered))->not->toContain('<script>alert(1)', '<b>Bold</b>', '<img src=x')
            ->and(app(DocumentRenderer::class)->web($tampered))->toContain('&lt;b&gt;Bold&lt;/b&gt;');
    });
});

describe('web view at 360 px', function () {
    it('lays out on a 360 px screen without sideways scrolling, in real Chrome', function (DocumentLayout $layout, string $kind, bool $void) {
        $gotenberg = requireGotenberg();
        $html = app(DocumentRenderer::class)->web(fullDocument($layout, $kind, 8, $void));

        $screen = NarrowScreen::measure($gotenberg, $html, 360);

        expect($screen['viewport'])->toBe(360)
            ->and($screen['overflow'])->toBe(0);
    })->with(layoutsAndKinds())->with(['issued' => false, 'voided' => true]);

    it('catches a page that is wider than the screen (the probe works)', function () {
        $gotenberg = requireGotenberg();

        $screen = NarrowScreen::measure($gotenberg, '<!DOCTYPE html><html><head><meta name="viewport" content="width=device-width"></head><body style="margin:0"><div style="width:500px;height:10px"></div></body></html>', 360);

        expect($screen['viewport'])->toBe(360)
            ->and($screen['overflow'])->toBeGreaterThan(100);
    });

    it('stacks the columns and moves hidden columns inline on phones, on web pages only', function (DocumentLayout $layout, string $kind) {
        $renderer = app(DocumentRenderer::class);
        $document = fullDocument($layout, $kind);
        $web = $renderer->web($document);

        expect($web)->toContain('name="viewport" content="width=device-width, initial-scale=1"', '@media (max-width: 640px)', 'td.col { display: block; width: 100% !important; }', '.wide-only { display: none; }', 'class="narrow-only')
            ->and($renderer->html($document, RenderTarget::Dompdf))->not->toContain('@media')
            ->and($renderer->html($document, RenderTarget::Chrome))->not->toContain('@media');
    })->with(layoutsAndKinds());
});

describe('table layouts and simple CSS only (BRANDING_DOCUMENTS.md § 5)', function () {
    it('finds no flex, grid or CSS variables in the invoice and receipt pages as rendered, for every layout and target', function (DocumentLayout $layout, string $kind, RenderTarget $target) {
        expect(DocumentCssLint::problems(app(DocumentRenderer::class)->html(fullDocument($layout, $kind, 8, true), $target)))->toBe([]);
    })->with(layoutsAndKinds())->with(RenderTarget::cases());
});

describe('the branding preview', function () {
    it('shows the designed sample invoice and receipt in the organization\'s layout', function (DocumentLayout $layout) {
        $preview = app(BrandingPreview::class);
        $brand = sampleBrand($layout);

        expect($preview->invoice($brand))->toContain('class="layout-'.$layout->value.'"', 'INV-ADR-', 'badge-unpaid">UNPAID', 'Pay by bank transfer', 'Your bank', '7KQ2M-X9P4D')
            ->and($preview->receipt($brand))->toContain('class="layout-'.$layout->value.'"', 'RCT-ADR-', 'Two million, nine hundred thousand naira only', 'R8X4P-2HC7N')
            ->and(SampleDocuments::invoice($brand)->view())->toBe('documents.invoice.'.$layout->value);
    })->with(DocumentLayout::cases());
});
