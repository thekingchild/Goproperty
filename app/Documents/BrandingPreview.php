<?php

declare(strict_types=1);

namespace App\Documents;

use App\Branding\Branding;
use App\Branding\BrandingFiles;
use App\Models\Organization;
use App\Theme\ThemeContext;

/**
 * Renders the sample invoice and receipt for an organization's branding
 * (BRANDING_DOCUMENTS.md § 1, "a live preview shows a sample invoice and
 * receipt while settings are edited"), with the same Blade templates and
 * the same HTML a PDF would get. The branding passed in need not be saved:
 * the settings page passes what the form holds now.
 */
final readonly class BrandingPreview
{
    public function __construct(
        private BrandingFiles $files,
        private ThemeContext $theme,
        private DocumentRenderer $renderer,
    ) {}

    /**
     * For web pages: images through the workspace route, or the given
     * sources (e.g. a file chosen but not saved yet).
     *
     * @param  array<string, string|null>  $sources  role => URL, overriding the stored file
     */
    public function forWeb(Organization $organization, Branding $branding, array $sources = []): DocumentBranding
    {
        $src = fn (string $role): ?string => array_key_exists($role, $sources) ? $sources[$role] : $this->files->url($organization, $role, $branding->mediaId($role));

        return new DocumentBranding($branding, $this->productName(), ! $branding->hidePoweredBy, $src('logo'), $src('signature'), $src('stamp'));
    }

    /** For PDFs: images embedded as data URIs, so neither driver fetches anything. */
    public function forPdf(Organization $organization, Branding $branding): DocumentBranding
    {
        return new DocumentBranding(
            $branding,
            $this->productName(),
            ! $branding->hidePoweredBy,
            $this->files->dataUri($organization, 'logo', $branding->logoMediaId),
            $this->files->dataUri($organization, 'signature', $branding->signatureMediaId),
            $this->files->dataUri($organization, 'stamp', $branding->stampMediaId),
        );
    }

    public function invoice(DocumentBranding $brand): string
    {
        return $this->renderer->web(SampleDocuments::invoice($brand));
    }

    public function receipt(DocumentBranding $brand): string
    {
        return $this->renderer->web(SampleDocuments::receipt($brand));
    }

    private function productName(): string
    {
        return $this->theme->current()->name();
    }
}
