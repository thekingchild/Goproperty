<?php

declare(strict_types=1);

use App\Documents\DocumentLayout;
use App\Documents\DocumentRenderer;
use App\Documents\KitSample;
use App\Documents\PdfPages;
use App\Documents\QrCode;
use App\Documents\ViewDocument;
use Tests\Support\PdfVisualDiff;

require_once __DIR__.'/../../Support/documents.php';

/*
 * P03-05a acceptance: a sample page of the document kit renders in both PDF
 * drivers within the visual-diff threshold (BRANDING_DOCUMENTS.md § 5).
 * dompdf is always tested; the Chrome half needs Gotenberg, which CI runs
 * as a service (.github/workflows/ci.yml). Locally, without Docker, the
 * Chrome half is skipped only when Gotenberg does not answer (orchestrator
 * decision 6); on CI an unreachable Gotenberg fails the test.
 */

/** Gotenberg's URL, or skip (locally) / fail (CI) with the reason. */
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

describe('acceptance: a sample page renders in both drivers within the diff threshold', function () {
    it('prints the sample page on one A4 page with dompdf, in the bundled fonts', function (DocumentLayout $layout) {
        $pdf = app(DocumentRenderer::class)->pdf(KitSample::document(sampleBrand($layout)), 'dompdf');

        expect($pdf)->toStartWith('%PDF-')
            ->and(PdfPages::count($pdf))->toBe(1)
            ->and($pdf)->toContain('/MediaBox [0.000 0.000 595.280 841.890]')
            ->and($pdf)->toMatch('#/BaseFont /[A-Z]{6}\+Inter-Regular#')
            ->and($pdf)->toMatch('#/BaseFont /[A-Z]{6}\+DejaVuSansMono-Bold#');

        if ($layout === DocumentLayout::Classic) {
            expect($pdf)->toMatch('#/BaseFont /[A-Z]{6}\+DejaVuSerif-Bold#');
        }

        requirePdfTools();
        $text = PdfVisualDiff::text($pdf);
        [$page] = PdfVisualDiff::rasterise($pdf);

        // The brand bar in the primary colour (#0E6B5C, grey 79 or so) across the top.
        expect($text)->toContain('Three million, two hundred', 'fifty kobo only', '₦3,202,500.50', '7KQ2M-X9P4D', 'Page 1 of 1', 'Generated with GoProperty')
            ->and($page[1][200])->toBeLessThan(120)
            ->and($page[1][200])->toBeGreaterThan(40)
            ->and($page[30][10])->toBe(255);
    })->with(DocumentLayout::cases());

    it('prints the sample page with Chrome (Gotenberg) the same as dompdf, within the threshold', function (DocumentLayout $layout) {
        requirePdfTools();
        requireGotenberg();
        $document = KitSample::document(sampleBrand($layout));
        $renderer = app(DocumentRenderer::class);

        $dompdf = $renderer->pdf($document, 'dompdf');
        $chrome = $renderer->pdf($document, 'chrome');
        $diff = PdfVisualDiff::compare($dompdf, $chrome);

        expect(PdfPages::count($chrome))->toBe(1)
            ->and(PdfVisualDiff::text($chrome))->toContain('Page 1 of 1', '7KQ2M-X9P4D', '₦3,202,500.50')
            ->and($diff['pages'])->toBe([1, 1])
            ->and($diff['score'])->toBeLessThan(PdfVisualDiff::THRESHOLD);
    })->with(DocumentLayout::cases());
});

describe('the visual diff itself', function () {
    it('scores a page against itself as identical', function () {
        requirePdfTools();
        $pdf = app(DocumentRenderer::class)->pdf(KitSample::document(sampleBrand()), 'dompdf');

        expect(PdfVisualDiff::compare($pdf, $pdf)['score'])->toBe(0.0);
    });

    it('catches real differences: another layout, a missing row, a wrapped line', function () {
        requirePdfTools();
        $renderer = app(DocumentRenderer::class);
        $brand = sampleBrand();
        $sample = KitSample::document($brand);
        $data = $sample->data();
        $page = $renderer->pdf($sample, 'dompdf');

        $classic = $renderer->pdf(KitSample::document(sampleBrand(DocumentLayout::Classic)), 'dompdf');
        $rowMissing = $renderer->pdf(new ViewDocument('documents.kit-sample', [...$data, 'rows' => array_slice($data['rows'], 1)], $brand), 'dompdf');
        $longerNote = $renderer->pdf(new ViewDocument('documents.kit-sample', [...$data, 'notes' => $data['notes'].' Ask for a receipt every time you pay, and keep it.'], $brand), 'dompdf');

        expect(PdfVisualDiff::compare($page, $classic)['score'])->toBeGreaterThan(PdfVisualDiff::THRESHOLD)
            ->and(PdfVisualDiff::compare($page, $rowMissing)['score'])->toBeGreaterThan(PdfVisualDiff::THRESHOLD)
            ->and(PdfVisualDiff::compare($page, $longerNote)['score'])->toBeGreaterThan(0.0005);
    });
});

it('draws QR codes that hold the verify URL, with finder patterns in three corners', function () {
    $url = 'https://goproperty.ng/verify/7KQ2MX9P4D';
    $modules = QrCode::modules($url);
    $size = count($modules);
    $png = QrCode::png($url);
    $image = imagecreatefromstring($png);

    // A finder pattern: a 7×7 dark ring, light ring, 3×3 dark centre.
    $finder = fn (int $left, int $top): bool => $modules[$top][$left] && $modules[$top + 6][$left + 6] && ! $modules[$top + 1][$left + 1] && $modules[$top + 3][$left + 3];

    expect($size)->toBe(29) // version 3 at error correction M
        ->and($finder(0, 0))->toBeTrue()
        ->and($finder($size - 7, 0))->toBeTrue()
        ->and($finder(0, $size - 7))->toBeTrue()
        ->and($finder($size - 7, $size - 7))->toBeFalse()
        ->and(imagesx($image))->toBe(($size + 2 * QrCode::QUIET_ZONE) * QrCode::SCALE)
        ->and(QrCode::dataUri($url))->toBe('data:image/png;base64,'.base64_encode($png))
        ->and(QrCode::modules('https://goproperty.ng/verify/R8X4P2HC7N'))->not->toBe($modules)
        ->and(fn () => QrCode::png(''))->toThrow(InvalidArgumentException::class);
});
