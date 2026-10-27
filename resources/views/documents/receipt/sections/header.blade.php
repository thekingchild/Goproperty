{{-- Letterhead with OFFICIAL RECEIPT, number, date issued and tenant ID (BRANDING_DOCUMENTS.md § 3 item 1). --}}
@include('documents.partials.letterhead', [
    'brand' => $brand,
    'title' => 'OFFICIAL RECEIPT',
    'titleClass' => 'doc-title-receipt',
    'meta' => array_filter([
        'Receipt no.' => e($receipt->number),
        'Date issued' => e($receipt->issuedOnLabel()),
        'Tenant ID' => $receipt->tenant->reference !== null ? e($receipt->tenant->reference) : null,
    ], fn (?string $value): bool => $value !== null),
])
