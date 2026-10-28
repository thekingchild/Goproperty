{{-- Letterhead with OWNER STATEMENT, the period, the owner and where the month stands (BRANDING_DOCUMENTS.md § 4). --}}
@include('documents.partials.letterhead', [
    'brand' => $brand,
    'title' => 'OWNER STATEMENT',
    'titleClass' => 'doc-title-statement',
    'meta' => [
        'Period' => e($statement->month->label()),
        'Owner' => e($statement->ownerName),
        'Prepared' => e($document->preparedAt->setTimezone('Africa/Lagos')->format('d/m/Y')),
        'Status' => e($document->statusWord()),
    ],
])
