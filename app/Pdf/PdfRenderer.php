<?php

declare(strict_types=1);

namespace App\Pdf;

/**
 * Turns a rendered Blade document into PDF bytes. The driver comes from
 * `PDF_DRIVER` (dompdf or chrome, ARCHITECTURE.md § 12.1); templates must
 * render the same on both (BRANDING_DOCUMENTS.md § 5).
 */
interface PdfRenderer
{
    public function render(string $html, PaperSize $paper = PaperSize::A4, Orientation $orientation = Orientation::Portrait): string;
}
