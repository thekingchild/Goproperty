<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Media: what may be uploaded, conversions and links (ARCHITECTURE.md § 9–10)
|--------------------------------------------------------------------------
|
| Every upload is checked by App\Media\UploadValidator against its
| collection here: the type is sniffed from the file's bytes (finfo; the
| browser's type and the file name are ignored), SVG is never accepted,
| and each collection has its own size cap. Images must also decode, and
| stay within `max_side` and `max_megapixels` so GD can convert them inside
| a shared host's memory limit. The Spatie package settings are in
| config/media-library.php.
|
| Spreadsheets (.xlsx, .csv) need a content check beyond the sniffed type:
| an .xlsx must open as a zip holding an Excel workbook, a .csv must be
| plain text (UploadValidator).
|
| A module that needs another collection adds it here (or merges it into
| this config from its service provider) with the narrowest list of types.
|
| Every collection is one of two kinds (ARCHITECTURE.md § 9 "Attachments vs
| evidence"): `attachment` files may be deleted for good by a permitted
| member; `evidence` files are records (AGENT.md § 3 rule 7) and App\Media\Media
| refuses to delete them (only the NDPA erasure tooling may, through
| Media::purging()).
|
*/

return [

    // Signed links to /media/{organization}/{media}/{conversion?} expire
    // after this many minutes (App\Media\MediaUrls).
    'url_lifetime_minutes' => (int) env('MEDIA_URL_LIFETIME', 15),

    // Images larger than this are refused: decoding one with GD takes about
    // 4–5 bytes a pixel (25 MP ≈ 120 MB), and the queue tick runs with
    // QUEUE_TICK_MEMORY_MB (192 MB). Phone photos are 12 MP. Converting a
    // 25 MP photo took 1.8 s and 110 MB more memory in P02-05's test; on a
    // host whose PHP memory_limit is below 256M, lower MEDIA_MAX_MEGAPIXELS.
    'max_side' => 8000,
    'max_megapixels' => (int) env('MEDIA_MAX_MEGAPIXELS', 25),

    // Queued WebP versions made with GD (App\Media\HasOrganizationMedia),
    // never larger than the original. The route serves them by name.
    'conversions' => [
        'thumb' => ['width' => 400, 'height' => 400, 'quality' => 80],
        'large' => ['width' => 1600, 'height' => 1600, 'quality' => 82],
    ],

    'collections' => [
        // Building, unit and Building Group photos.
        'photos' => [
            'mimes' => ['image/jpeg', 'image/png', 'image/webp'],
            'max_kb' => 10 * 1024,
            'conversions' => ['thumb', 'large'],
            'kind' => 'attachment',
        ],

        // Payment proofs, receipts for expenses, signed papers: a photo or a PDF.
        // Conversions apply to the images only.
        'documents' => [
            'mimes' => ['application/pdf', 'image/jpeg', 'image/png'],
            'max_kb' => 10 * 1024,
            'conversions' => ['thumb'],
            'kind' => 'evidence',
        ],

        // Organization logo, signature and stamp (App\Branding\BrandingFiles):
        // stored already re-encoded as PNG, at most 800 × 500 px. Kept like
        // evidence: replaced images stay for documents issued with them.
        'branding' => [
            'mimes' => ['image/png'],
            'max_kb' => 2 * 1024,
            'conversions' => [],
            'kind' => 'evidence',
        ],

        // Spreadsheets uploaded for import (Modules\Import, DATA_IMPORT.md
        // § 4): .xlsx (a zip holding an Excel workbook, no macros) or .csv
        // (plain text, no NUL bytes, no markup or script). Kept 30 days,
        // then purged. 10,000 rows a sheet fit well within the cap.
        'imports' => [
            'mimes' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'text/csv'],
            'max_kb' => 20 * 1024,
            'conversions' => [],
            'kind' => 'attachment',
        ],

        // PDFs of issued documents (invoices, receipts, …), rendered once by
        // App\Documents\DocumentRenderer and reused (BRANDING_DOCUMENTS.md § 5).
        'issued_documents' => [
            'mimes' => ['application/pdf'],
            'max_kb' => 10 * 1024,
            'conversions' => [],
            'kind' => 'evidence',
        ],
    ],

];
