{{-- Letterhead with UTILITY BILL, number, dates and the status badge (BRANDING_DOCUMENTS.md § 2 item 2, § 4). --}}
@include('documents.partials.letterhead', [
    'brand' => $brand,
    'title' => 'UTILITY BILL',
    'meta' => [
        'Invoice no.' => e($invoice->number),
        'Issue date' => e($invoice->issuedOnLabel()),
        'Due date' => e($invoice->dueOnLabel()),
        'Status' => view('documents.partials.status-badge', ['status' => $invoice->status])->render(),
    ],
])
