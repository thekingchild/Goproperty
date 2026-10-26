<?php

declare(strict_types=1);

namespace App\Pdf;

use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Manager;
use RuntimeException;

/** Picks the PDF driver named by `pdf.driver` (`PDF_DRIVER`). */
final class PdfManager extends Manager
{
    public function getDefaultDriver(): string
    {
        $driver = $this->config->get('pdf.driver');

        return is_string($driver) && $driver !== '' ? $driver : 'dompdf';
    }

    public function renderer(?string $driver = null): PdfRenderer
    {
        $renderer = $this->driver($driver);

        if (! $renderer instanceof PdfRenderer) {
            throw new RuntimeException('PDF driver ['.($driver ?? $this->getDefaultDriver()).'] is not a PdfRenderer.');
        }

        return $renderer;
    }

    protected function createDompdfDriver(): PdfRenderer
    {
        return new DompdfRenderer(
            cacheDir: $this->string('pdf.dompdf.cache_dir', storage_path('framework/cache/dompdf')),
            defaultFont: $this->string('pdf.dompdf.default_font', 'DejaVu Sans'),
            dpi: (int) $this->string('pdf.dompdf.dpi', '96'),
            chroot: [resource_path(), public_path()],
        );
    }

    protected function createChromeDriver(): PdfRenderer
    {
        $url = $this->string('pdf.chrome.url', '');

        if ($url === '') {
            throw new RuntimeException('PDF_DRIVER=chrome needs PDF_CHROME_URL (the Gotenberg service).');
        }

        $username = $this->string('pdf.chrome.username', '');

        return new ChromeRenderer(
            http: $this->container->make(Http::class),
            url: $url,
            timeout: (int) $this->string('pdf.chrome.timeout', '30'),
            username: $username === '' ? null : $username,
            password: $this->string('pdf.chrome.password', ''),
        );
    }

    private function string(string $key, string $default): string
    {
        $value = $this->config->get($key);

        return is_scalar($value) && (string) $value !== '' ? (string) $value : $default;
    }
}
