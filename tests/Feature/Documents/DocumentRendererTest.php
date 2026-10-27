<?php

declare(strict_types=1);

use App\Documents\BrandingPreview;
use App\Documents\DocumentLayout;
use App\Documents\DocumentRenderer;
use App\Documents\KitSample;
use App\Documents\PdfPages;
use App\Documents\RenderTarget;
use App\Documents\ViewDocument;
use App\Media\EvidenceFileProtected;
use App\Media\Media;
use App\Models\Organization;
use App\Tenancy\CrossOrganizationWrite;
use App\Tenancy\MissingOrganizationContext;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PdfVisualDiff;

require_once __DIR__.'/../../Support/compliance.php';
require_once __DIR__.'/../../Support/documents.php';

/*
 * P03-05a: DocumentRenderer (BRANDING_DOCUMENTS.md § 5, ARCHITECTURE.md
 * § 6.5): web page and PDF from one view; an issued document's PDF is
 * rendered once, kept as an evidence file and reused.
 */

beforeEach(function () {
    Storage::fake('media');
    $this->acme = Organization::factory()->create(['name' => 'Adeyemi Realty Ltd']);
    $this->other = Organization::factory()->create(['name' => 'Other Homes Ltd']);
});

afterEach(fn () => app(OrganizationContext::class)->clear());

describe('acceptance: a stored PDF is reused, not re-rendered', function () {
    it('renders an issued document once and returns the kept file afterwards', function () {
        $counter = countingPdfDriver();
        $renderer = app(DocumentRenderer::class);

        $first = inOrganization($this->acme, fn () => $renderer->stored($this->acme, 'statement', KitSample::document(sampleBrand())));
        $bytes = Storage::disk('media')->get($first->getPathRelativeToRoot());

        // Later calls, even with other content (new branding, another layout), get the kept file.
        $second = inOrganization($this->acme, fn () => $renderer->stored($this->acme, 'statement', KitSample::document(sampleBrand(DocumentLayout::Classic))));
        $third = inOrganization($this->acme, fn () => $renderer->stored($this->acme->fresh(), 'statement', KitSample::document(sampleBrand())));

        expect($counter->renders)->toBe(1)
            ->and($second->id)->toBe($first->id)
            ->and($third->id)->toBe($first->id)
            ->and(Storage::disk('media')->get($second->getPathRelativeToRoot()))->toBe($bytes)
            ->and($bytes)->toStartWith('%PDF-')
            ->and($first->collection_name)->toBe('issued_documents')
            ->and($first->mime_type)->toBe('application/pdf')
            ->and($first->name)->toBe('ST-ADR-2027-000007')
            ->and($first->getCustomProperty('document_key'))->toBe('statement')
            ->and($first->getCustomProperty('pdf_driver'))->toBe('counting')
            ->and($first->getCustomProperty('pages'))->toBe(1)
            ->and($first->checksum())->toBe(hash('sha256', $bytes))
            ->and(inOrganization($this->acme, fn () => Media::query()->where('collection_name', 'issued_documents')->count()))->toBe(1);
    });

    it('keeps one file per document key, and finds it without rendering', function () {
        $counter = countingPdfDriver();
        $renderer = app(DocumentRenderer::class);

        $receipt = inOrganization($this->acme, fn () => $renderer->stored($this->acme, 'receipt', KitSample::document(sampleBrand())));
        $void = inOrganization($this->acme, fn () => $renderer->stored($this->acme, 'receipt-void', KitSample::document(sampleBrand())));

        expect($counter->renders)->toBe(2)
            ->and($void->id)->not->toBe($receipt->id)
            ->and(inOrganization($this->acme, fn () => $renderer->kept($this->acme, 'receipt'))?->id)->toBe($receipt->id)
            ->and(inOrganization($this->acme, fn () => $renderer->kept($this->acme, 'invoice')))->toBeNull()
            ->and($counter->renders)->toBe(2);
    });

    it('never reuses another organization\'s file, and never stores one on another organization\'s record', function () {
        countingPdfDriver();
        $renderer = app(DocumentRenderer::class);
        $ours = inOrganization($this->acme, fn () => $renderer->stored($this->acme, 'statement', KitSample::document(sampleBrand())));

        expect(inOrganization($this->other, fn () => $renderer->kept($this->acme, 'statement')))->toBeNull()
            ->and(fn () => inOrganization($this->other, fn () => $renderer->stored($this->acme, 'statement', KitSample::document(sampleBrand()))))->toThrow(CrossOrganizationWrite::class)
            ->and(fn () => inOrganization($this->other, fn () => $renderer->stored($this->acme, 'invoice', KitSample::document(sampleBrand()))))->toThrow(CrossOrganizationWrite::class)
            ->and(fn () => $renderer->stored($this->acme, 'statement', KitSample::document(sampleBrand())))->toThrow(MissingOrganizationContext::class)
            ->and(inOrganization($this->acme, fn () => Media::query()->pluck('id')->all()))->toBe([$ours->id]);
    });

    it('keeps the stored PDF as evidence: it cannot be deleted', function () {
        countingPdfDriver();
        $media = inOrganization($this->acme, fn () => app(DocumentRenderer::class)->stored($this->acme, 'statement', KitSample::document(sampleBrand())));

        expect(fn () => inOrganization($this->acme, fn () => $media->delete()))->toThrow(EvidenceFileProtected::class)
            ->and(inOrganization($this->acme, fn () => Media::query()->whereKey($media->id)->exists()))->toBeTrue()
            ->and(Storage::disk('media')->exists($media->getPathRelativeToRoot()))->toBeTrue();
    });

    it('refuses a key that is not a short name, and an unsaved owner', function () {
        expect(fn () => inOrganization($this->acme, fn () => app(DocumentRenderer::class)->stored($this->acme, '../x', KitSample::document(sampleBrand()))))->toThrow(InvalidArgumentException::class)
            ->and(fn () => inOrganization($this->acme, fn () => app(DocumentRenderer::class)->stored(new Organization, 'statement', KitSample::document(sampleBrand()))))->toThrow(InvalidArgumentException::class);
    });
});

describe('web pages and PDFs from one view', function () {
    it('renders the web page with fonts by URL, a viewport and no page numbers', function () {
        $html = app(DocumentRenderer::class)->web(KitSample::document(sampleBrand()));

        expect($html)->toContain('name="viewport"', asset('fonts/documents/Inter-400-normal.ttf'), 'max-width: 794px', 'Adeyemi Realty Ltd · Statement ST-ADR-2027-000007')
            ->and($html)->not->toContain('Page ', 'counter(page)', '@bottom-left', 'base64,AAEAAA');
    });

    it('gives dompdf the font files and a fixed footer, and Chrome embedded fonts and margin boxes', function () {
        $document = KitSample::document(sampleBrand());
        $dompdf = app(DocumentRenderer::class)->html($document, RenderTarget::Dompdf, 3);
        $chrome = app(DocumentRenderer::class)->html($document, RenderTarget::Chrome);

        expect($dompdf)->toContain(public_path('fonts/documents/Inter-400-normal.ttf'), 'class="page-number"', 'of 3', 'bottom: -40px', '@page { size: A4; margin: 0 0 40px 0; }')
            ->and($dompdf)->not->toContain('@bottom-left')
            ->and($chrome)->toContain('data:font/ttf;base64,', '@bottom-left', 'counter(page)', 'counter(pages)', '@page { size: A4; margin: 0 0 40px 0; }')
            ->and($chrome)->not->toContain('class="footer"');
    });

    it('escapes everything it writes into Chrome\'s footer strings', function () {
        $brand = sampleBrand();
        $document = new ViewDocument('documents.kit-sample', [...KitSample::document($brand)->data(), 'number' => '"; } </style><script>x</script>'], $brand);

        $chrome = app(DocumentRenderer::class)->html($document, RenderTarget::Chrome);

        expect($chrome)->not->toContain('</style><script>')
            ->and($chrome)->toContain('\\00003c \\00002f style\\00003e ');
    });

    it('prints "page x of y" on every page with dompdf, counting the pages first', function () {
        requirePdfTools();
        $brand = sampleBrand();
        $rows = array_fill(0, 40, ['description' => 'Service charge', 'note' => 'Cleaning, generator, common areas', 'period' => '2027', 'amount' => '₦350,000']);
        $long = new ViewDocument('documents.kit-sample', [...KitSample::document($brand)->data(), 'rows' => $rows], $brand);

        $pdf = app(DocumentRenderer::class)->pdf($long, 'dompdf');
        $text = PdfVisualDiff::text($pdf);
        $pages = PdfPages::count($pdf);

        expect($pages)->toBeGreaterThan(1);

        foreach (range(1, $pages) as $page) {
            expect($text)->toContain("Page {$page} of {$pages}");
        }

        expect(PdfVisualDiff::text(app(DocumentRenderer::class)->pdf(KitSample::document($brand), 'dompdf')))->toContain('Page 1 of 1');
    });
});

it('shows the settings preview through the kit: QR code, amount in words, brand bar', function () {
    $preview = app(BrandingPreview::class);
    $brand = sampleBrand();

    $receipt = $preview->receipt($brand);
    $invoice = $preview->invoice($brand);

    expect($receipt)->toContain('Two million, nine hundred thousand naira only', 'data:image/png;base64,', 'R8X4P-2HC7N', 'class="brand-bar"')
        ->and($invoice)->toContain('7KQ2M-X9P4D', 'Verify this invoice', url('/verify/7KQ2MX9P4D'));
});
