<?php

declare(strict_types=1);

use App\Pdf\ChromeRenderer;
use App\Pdf\DompdfRenderer;
use App\Pdf\Orientation;
use App\Pdf\PaperSize;
use App\Pdf\PdfManager;
use App\Pdf\PdfRenderer;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

const SAMPLE_DOCUMENT = <<<'HTML'
    <html><head><style>@page { margin: 15mm; } body { font-family: "DejaVu Sans"; }</style></head>
    <body><table><tr><td>Rent, Flat 2B</td><td>₦250,000.00</td></tr></table></body></html>
    HTML;

/** @return array<string, string> multipart field name => contents */
function multipartFields(Request $request): array
{
    return collect($request->data())
        ->reject(fn (array $part): bool => isset($part['filename']))
        ->mapWithKeys(fn (array $part): array => [$part['name'] => (string) $part['contents']])
        ->all();
}

it('uses dompdf on the shared-hosting profile', function () {
    expect(app(PdfRenderer::class))->toBeInstanceOf(DompdfRenderer::class);
});

it('renders a document with dompdf', function (PaperSize $paper, Orientation $orientation, string $mediaBox) {
    $pdf = app(PdfManager::class)->renderer('dompdf')->render(SAMPLE_DOCUMENT, $paper, $orientation);

    expect($pdf)->toStartWith('%PDF-')
        ->and($pdf)->toContain("/MediaBox [{$mediaBox}]");
})->with([
    'A4 portrait' => [PaperSize::A4, Orientation::Portrait, '0.000 0.000 595.280 841.890'],
    'A4 landscape' => [PaperSize::A4, Orientation::Landscape, '0.000 0.000 841.890 595.280'],
]);

it('lets the template\'s @page size win, as Chrome does', function () {
    $html = str_replace('@page { margin: 15mm; }', '@page { size: A4 landscape; margin: 15mm; }', SAMPLE_DOCUMENT);

    $pdf = app(PdfManager::class)->renderer('dompdf')->render($html, PaperSize::A4, Orientation::Portrait);

    expect($pdf)->toContain('/MediaBox [0.000 0.000 841.890 595.280]');
});

describe('chrome driver', function () {
    beforeEach(function () {
        config([
            'pdf.driver' => 'chrome',
            'pdf.chrome.url' => 'http://gotenberg.test/',
            'pdf.chrome.username' => null,
            'pdf.chrome.password' => null,
        ]);
    });

    it('converts the HTML through Gotenberg', function () {
        Http::fake(['gotenberg.test/*' => Http::response('%PDF-1.7 from chrome', 200, ['Content-Type' => 'application/pdf'])]);

        $renderer = app(PdfRenderer::class);
        $pdf = $renderer->render(SAMPLE_DOCUMENT, PaperSize::A4, Orientation::Landscape);

        expect($renderer)->toBeInstanceOf(ChromeRenderer::class)
            ->and($pdf)->toBe('%PDF-1.7 from chrome');

        Http::assertSent(function (Request $request): bool {
            $file = collect($request->data())->firstWhere('name', 'files');

            return $request->url() === 'http://gotenberg.test/forms/chromium/convert/html'
                && $request->isMultipart()
                && ! $request->hasHeader('Authorization')
                && ($file['filename'] ?? null) === 'index.html'
                && str_contains((string) ($file['contents'] ?? ''), '₦250,000.00')
                && multipartFields($request) === [
                    'paperWidth' => '8.27',
                    'paperHeight' => '11.69',
                    'landscape' => 'true',
                    'printBackground' => 'true',
                    'preferCssPageSize' => 'true',
                ];
        });
    });

    it('sends basic auth when the service needs it', function () {
        config(['pdf.chrome.username' => 'gotenberg', 'pdf.chrome.password' => 'secret']);
        Http::fake(['gotenberg.test/*' => Http::response('%PDF-1.7')]);

        app(PdfRenderer::class)->render(SAMPLE_DOCUMENT);

        Http::assertSent(fn (Request $request): bool => $request->header('Authorization') === ['Basic '.base64_encode('gotenberg:secret')]);
    });

    it('fails loudly when Gotenberg returns an error', function () {
        Http::fake(['gotenberg.test/*' => Http::response('Chromium crashed', 503)]);

        app(PdfRenderer::class)->render(SAMPLE_DOCUMENT);
    })->throws(RequestException::class);

    it('needs the Gotenberg URL', function () {
        config(['pdf.chrome.url' => null]);

        app(PdfRenderer::class);
    })->throws(RuntimeException::class, 'PDF_DRIVER=chrome needs PDF_CHROME_URL');
});

it('rejects an unknown driver', function () {
    config(['pdf.driver' => 'wkhtmltopdf']);

    app(PdfRenderer::class);
})->throws(InvalidArgumentException::class);
