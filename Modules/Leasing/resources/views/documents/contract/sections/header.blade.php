{{-- Letterhead with TENANCY AGREEMENT, contract number, date issued and, for a renewal, the contract it renews (BRANDING_DOCUMENTS.md § 4). --}}
@include('documents.partials.letterhead', [
    'brand' => $brand,
    'title' => 'TENANCY AGREEMENT',
    'titleClass' => 'doc-title-contract',
    'meta' => array_filter([
        'Contract no.' => e($contract->number),
        'Date issued' => e($contract->issuedOnLabel()),
        'Renews' => $contract->renewalOf !== null ? e($contract->renewalOf) : null,
    ], fn (?string $value): bool => $value !== null),
])
