<?php

declare(strict_types=1);

use App\Branding\Branding;
use App\Documents\DocumentBranding;
use App\Documents\DocumentLayout;
use App\Pdf\Orientation;
use App\Pdf\PaperSize;
use App\Pdf\PdfManager;
use App\Pdf\PdfRenderer;
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
