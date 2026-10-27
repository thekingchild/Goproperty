<?php

declare(strict_types=1);

namespace App\Documents;

/**
 * One printable document, as DocumentRenderer takes it: a Blade view under
 * resources/views/documents, its data, and the branding it is printed with
 * (for an issued document, its BrandingSnapshot's documentBranding(); for
 * the settings preview, the unsaved form). Modules build these from their
 * own records (P03-05b: InvoiceDocument, ReceiptDocument).
 *
 * The view receives `brand` (DocumentBranding), `target` (RenderTarget),
 * `pageCount` (int; dompdf's footer total) and everything data() returns.
 */
interface Document
{
    /** @return view-string */
    public function view(): string;

    /** @return array<string, mixed> */
    public function data(): array;

    public function brand(): DocumentBranding;

    /** "INV-ADR-2027-000142.pdf": the name a downloaded PDF gets. */
    public function fileName(): string;
}
