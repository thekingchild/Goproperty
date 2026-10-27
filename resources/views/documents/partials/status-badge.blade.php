{{-- An invoice's status badge (App\Documents\Invoice\InvoiceStatus; BRANDING_DOCUMENTS.md § 2 item 2). --}}
<span class="badge {{ $status->badgeClass() }}">{{ $status->label() }}</span>
