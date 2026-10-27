<?php

declare(strict_types=1);

use App\Branding\Branding;
use App\Documents\DocumentBranding;
use App\Documents\DocumentLayout;
use App\Documents\Invoice\BankTransfer;
use App\Documents\Invoice\InvoiceDocument;
use App\Documents\Invoice\InvoiceLine;
use App\Documents\Invoice\InvoiceStatus;
use App\Documents\Invoice\OnlinePayment;
use App\Documents\Parts\DocumentPeriod;
use App\Documents\Parts\DocumentProperty;
use App\Documents\Parts\DocumentTenant;
use App\Documents\Receipt\ReceiptAllocation;
use App\Documents\Receipt\ReceiptDocument;
use App\Documents\Receipt\ReceiptPayment;
use App\Pdf\Orientation;
use App\Pdf\PaperSize;
use App\Pdf\PdfManager;
use App\Pdf\PdfRenderer;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Tests\Support\PdfVisualDiff;

/*
 * Helpers for tests/Feature/Documents (P03-05a): a fixed branding for
 * sample pages, a PDF driver that counts its renders, real PNG uploads and
 * the rule for the tools the visual comparison needs.
 */

/** Adeyemi Realty's branding in this layout, as a snapshot would hold it. */
function sampleBranding(DocumentLayout $layout = DocumentLayout::Modern, array $changes = []): Branding
{
    return Branding::fromSnapshot($changes + [
        'company_name' => 'Adeyemi Realty Ltd',
        'trading_name' => 'Adeyemi Realty',
        'primary_color' => '#0E6B5C',
        'accent_color' => '#F2B53A',
        'address' => '14 Admiralty Way, Lekki Phase 1, Lagos',
        'rc_number' => 'RC 1234567',
        'phone' => '+234 803 000 0000',
        'email' => 'hello@adeyemi.ng',
        'website' => 'adeyemi.ng',
        'invoice_prefix' => 'INV-ADR',
        'receipt_prefix' => 'RCT-ADR',
        'lease_prefix' => 'LS-ADR',
        'layout' => $layout->value,
    ]);
}

function sampleBrand(DocumentLayout $layout = DocumentLayout::Modern): DocumentBranding
{
    return new DocumentBranding(sampleBranding($layout), 'GoProperty', true);
}

/**
 * Registers PDF driver "counting" (dompdf underneath) and makes it the
 * default; the returned object counts the renders.
 */
function countingPdfDriver(): object
{
    $counter = new class
    {
        public int $renders = 0;
    };

    app(PdfManager::class)->extend('counting', fn () => new class($counter) implements PdfRenderer
    {
        public function __construct(private object $counter) {}

        public function render(string $html, PaperSize $paper = PaperSize::A4, Orientation $orientation = Orientation::Portrait): string
        {
            $this->counter->renders++;

            return app(PdfManager::class)->renderer('dompdf')->render($html, $paper, $orientation);
        }
    });

    config(['pdf.driver' => 'counting']);

    return $counter;
}

/** A real PNG image upload. */
function documentPng(string $name, int $width = 400, int $height = 160, array $rgb = [14, 107, 92]): UploadedFile
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
    imagefilledrectangle($image, 10, 10, $width - 11, $height - 11, (int) imagecolorallocate($image, ...$rgb));
    $path = tempnam(sys_get_temp_dir(), 'gp-doc-test-').'.png';
    imagepng($image, $path);

    return new UploadedFile($path, $name, 'image/png', null, true);
}

/**
 * Gotenberg's URL, or skip (locally) / fail (CI) with the reason: CI runs
 * it as a service, so the Chrome half of a test is skipped only on a
 * developer's machine without it (orchestrator decision 6).
 */
function requireGotenberg(): string
{
    $url = PdfVisualDiff::gotenberg();

    if ($url !== null) {
        config(['pdf.chrome.url' => $url]);

        return $url;
    }

    $tried = config('pdf.chrome.url') ?: 'http://127.0.0.1:3000';

    if (getenv('CI') !== false && getenv('CI') !== '') {
        test()->fail("Gotenberg is not reachable at {$tried}: CI must run the Chrome half (decision 6).");
    }

    test()->markTestSkipped("Gotenberg is not reachable at {$tried} (PDF_CHROME_URL): the Chrome half runs in CI.");
}

/**
 * Skips a test that needs poppler's tools when they are missing on a
 * developer's machine; on CI (which installs them) a missing tool fails.
 */
function requirePdfTools(): void
{
    $missing = PdfVisualDiff::unavailable();

    if ($missing === null) {
        return;
    }

    if (getenv('CI') !== false && getenv('CI') !== '') {
        test()->fail("CI must be able to compare PDFs: {$missing}.");
    }

    test()->markTestSkipped("{$missing}: install poppler-utils to compare PDFs locally.");
}

/*
 * P03-05b: the fullest invoice and receipt the one-page promise covers
 * (IMPLEMENTATION_PLAN.md § 3 P03-05: "one A4 page for up to 8 line
 * items"): every line with a note, credit and part payment, bank and online
 * payment, the longest notes and receipt footer the settings allow, and a
 * logo, signature and stamp.
 */

/** A PNG data URI of the given size, as a branding snapshot holds images. */
function documentImage(int $width, int $height, array $rgb = [14, 107, 92]): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
    imagefilledrectangle($image, 4, 4, $width - 5, $height - 5, (int) imagecolorallocate($image, ...$rgb));
    ob_start();
    imagepng($image);

    return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
}

/** Adeyemi Realty's branding with notes and footer at their longest, and all three images. */
function fullBrand(DocumentLayout $layout = DocumentLayout::Modern, array $changes = []): DocumentBranding
{
    $notes = 'Rent is payable in advance in line with your tenancy agreement. Please notify us of any payment by replying to your reminder message or uploading proof on your tenant portal. Service charge covers cleaning, the generator and diesel, security at the gate and lighting of the common areas. Caution fees are refunded at move-out after inspection. Cheques are not accepted. Thank you for staying with us; for any question about this invoice, call or email our accounts team.';
    $footer = 'Keep this receipt safe: it is your proof of payment. Questions about this receipt? Call or email our accounts team and quote the receipt number. Refunds are paid only into the account the payment came from.';

    return new DocumentBranding(
        sampleBranding($layout, $changes + [
            'invoice_notes' => str_pad($notes, 600, ' Thank you.'),
            'receipt_footer' => substr(str_pad($footer, 300, ' Thank you.'), 0, 300),
        ]),
        'GoProperty',
        true,
        documentImage(400, 160),
        documentImage(300, 120, [30, 41, 59]),
        documentImage(300, 300, [37, 99, 235]),
    );
}

/** An invoice with $lines lines, each with a note. */
function fullInvoice(DocumentBranding $brand, int $lines = 8, InvoiceStatus $status = InvoiceStatus::PartlyPaid, ?string $voidReason = null): InvoiceDocument
{
    $labels = [
        ['Rent — 3 bedroom flat', 'Annual rent, renewal year 3'],
        ['Service charge', 'Cleaning, generator, common areas'],
        ['Security levy', 'Gate, guards and night patrol'],
        ['Waste management (LAWMA)', 'Weekly collection'],
        ['Water rate', 'Borehole and treatment share'],
        ['Electricity (prepaid meter)', 'Meter 45012345678, top-up'],
        ['Agency fee', 'First year only, 10% of rent'],
        ['Legal fee', 'Tenancy agreement, first year only'],
        ['Caution fee', 'Refundable at move-out'],
        ['Parking space', 'Bay 14, covered'],
    ];
    $period = new DocumentPeriod(CarbonImmutable::parse('2027-01-01'), CarbonImmutable::parse('2027-12-31'));
    $items = [];

    foreach (range(0, $lines - 1) as $index) {
        [$description, $note] = $labels[$index % count($labels)];
        $items[] = new InvoiceLine($description, Money::kobo(350_000_000 - $index * 37_512_550), $note, $index === 7 ? 'Once' : null);
    }

    return new InvoiceDocument(
        brand: $brand,
        number: 'INV-ADR-2027-000142',
        issuedOn: CarbonImmutable::parse('2026-11-01'),
        dueOn: CarbonImmutable::parse('2026-12-31'),
        status: $status,
        tenant: new DocumentTenant('Chiamaka Adaeze Okafor-Williams', '+234 812 345 6789', 'chiamaka.okafor-williams@example.com', 'TN-0087'),
        property: new DocumentProperty('Palm Court Apartments', 'Flat 3B', '22 Fola Osibo Road, Lekki Phase 1, Lagos', 'LS-ADR-2024-000031', 'Annual'),
        lines: $items,
        paymentReference: 'GP-7KQ2M',
        verificationCode: '7KQ2MX9P4D',
        bank: new BankTransfer('Guaranty Trust Bank', 'Adeyemi Realty Ltd', '0123456789'),
        online: new OnlinePayment('https://goproperty.test/pay/GP-7KQ2M'),
        period: $period,
        credit: Money::kobo(3_400_000),
        paidToDate: Money::kobo(50_000_000),
        voidReason: $voidReason,
    );
}

/** A receipt applied to $allocations invoices. */
function fullReceipt(DocumentBranding $brand, int $allocations = 8, bool $void = false): ReceiptDocument
{
    $rows = [];

    foreach (range(1, $allocations) as $index) {
        $rows[] = new ReceiptAllocation('INV-ADR-2027-'.str_pad((string) (140 + $index), 6, '0', STR_PAD_LEFT), $index === 1 ? 'Rent, service charge, security levy, waste — 2027' : 'Utility bill, water and electricity — month '.$index, Money::kobo(36_250_000), settled: $index !== $allocations);
    }

    return new ReceiptDocument(
        brand: $brand,
        number: 'RCT-ADR-2026-000318',
        issuedAt: CarbonImmutable::parse('2026-12-14 09:42:00'),
        amount: Money::kobo(290_000_000),
        tenant: new DocumentTenant('Chiamaka Adaeze Okafor-Williams', '+234 812 345 6789', 'chiamaka.okafor-williams@example.com', 'TN-0087'),
        property: new DocumentProperty('Palm Court Apartments', 'Flat 3B', '22 Fola Osibo Road, Lekki Phase 1, Lagos', 'LS-ADR-2024-000031', 'Annual'),
        payment: new ReceiptPayment('Bank transfer', CarbonImmutable::parse('2026-12-13'), 'Matched from bank statement', 'Guaranty Trust Bank', '0123456789', 'GP-7KQ2M C OKAFOR RENT DEC'),
        allocations: $rows,
        outstandingAfter: Money::kobo(12_500_000),
        creditBalance: Money::zero(),
        confirmedBy: 'Babatunde Bakare-Johnson',
        confirmedAt: CarbonImmutable::parse('2026-12-14 09:42:00'),
        verificationCode: 'R8X4P2HC7N',
        sentVia: ['WhatsApp', 'email'],
        voidedAt: $void ? CarbonImmutable::parse('2026-12-15 08:10:00') : null,
        voidReason: $void ? 'Payment recorded twice; the bank statement shows one transfer.' : null,
    );
}
