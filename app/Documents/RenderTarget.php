<?php

declare(strict_types=1);

namespace App\Documents;

/**
 * What a document's HTML is rendered for (BRANDING_DOCUMENTS.md § 5). The
 * markup and styles are the same for all three; only what the drivers
 * cannot share differs: where the bundled fonts come from, and how the
 * footer's "page x of y" is drawn (dompdf: a fixed footer with its page
 * counter and the total from a first pass; Chrome: the @page margin boxes,
 * which count pages natively). Web pages have no page numbers.
 */
enum RenderTarget: string
{
    case Web = 'web';
    case Dompdf = 'dompdf';
    case Chrome = 'chrome';

    /** The target for a PDF driver name (`pdf.driver`, App\Pdf\PdfManager). */
    public static function forDriver(string $driver): self
    {
        return $driver === 'chrome' ? self::Chrome : self::Dompdf;
    }

    public function isPdf(): bool
    {
        return $this !== self::Web;
    }
}
