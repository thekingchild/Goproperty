<?php

declare(strict_types=1);

namespace App\Pdf;

use Illuminate\Http\Client\Factory as Http;

/**
 * Headless Chrome through a Gotenberg service (VPS add-on). Rendering is an
 * HTTP call, so the PHP server needs neither Node nor `proc_open`.
 */
final readonly class ChromeRenderer implements PdfRenderer
{
    public function __construct(
        private Http $http,
        private string $url,
        private int $timeout,
        private ?string $username = null,
        private ?string $password = null,
    ) {}

    public function render(string $html, PaperSize $paper = PaperSize::A4, Orientation $orientation = Orientation::Portrait): string
    {
        [$width, $height] = $paper->inches();

        $request = $this->http->timeout($this->timeout)->accept('application/pdf');

        if ($this->username !== null && $this->username !== '') {
            $request = $request->withBasicAuth($this->username, (string) $this->password);
        }

        return $request
            ->attach('files', $html, 'index.html', ['Content-Type' => 'text/html; charset=utf-8'])
            ->post(rtrim($this->url, '/').'/forms/chromium/convert/html', [
                'paperWidth' => (string) $width,
                'paperHeight' => (string) $height,
                'landscape' => $orientation === Orientation::Landscape ? 'true' : 'false',
                'printBackground' => 'true',
                // Let the template's @page rules win, as they do in dompdf.
                'preferCssPageSize' => 'true',
            ])
            ->throw()
            ->body();
    }
}
