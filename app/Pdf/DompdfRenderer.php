<?php

declare(strict_types=1);

namespace App\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;

/** Pure-PHP renderer: the shared-hosting driver. */
final readonly class DompdfRenderer implements PdfRenderer
{
    /** @param  list<string>  $chroot  only files under these paths may be read */
    public function __construct(
        private string $cacheDir,
        private string $defaultFont,
        private int $dpi,
        private array $chroot,
    ) {}

    public function render(string $html, PaperSize $paper = PaperSize::A4, Orientation $orientation = Orientation::Portrait): string
    {
        File::ensureDirectoryExists($this->cacheDir);

        $options = new Options;
        // Documents embed their images (data URIs or files under the chroot).
        // Remote fetches are off so a template can never be used for SSRF.
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setChroot($this->chroot);
        $options->setFontDir($this->cacheDir);
        $options->setFontCache($this->cacheDir);
        $options->setTempDir($this->cacheDir);
        $options->setDefaultFont($this->defaultFont);
        $options->setDpi($this->dpi);

        $dompdf = new Dompdf($options);
        $dompdf->setPaper($paper->value, $orientation->value);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
