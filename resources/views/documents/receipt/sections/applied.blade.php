{{-- Applied to: each invoice and amount; total, outstanding balance after payment, credit balance (BRANDING_DOCUMENTS.md § 3 item 5). --}}
<div class="label label-gap">Applied to</div>
<table class="items">
  <tr>
    <th style="width:32%">Invoice</th>
    <th class="wide-only" style="width:42%">Description</th>
    <th class="r" style="width:26%">Amount applied</th>
  </tr>
  @forelse ($receipt->allocations as $index => $allocation)
    <tr @class(['alt' => $index % 2 === 1])>
      <td><span class="nowrap">{{ $allocation->invoiceNumber }}</span>@if ($allocation->settled) <span class="chip">PAID</span>@endif<div class="narrow-only muted small">{{ $allocation->description }}</div></td>
      <td class="wide-only">{{ $allocation->description }}</td>
      <td class="r nowrap">{{ $allocation->amount->format() }}</td>
    </tr>
  @empty
    <tr><td colspan="3" class="muted">Held as credit on the tenant's account.</td></tr>
  @endforelse
</table>
<table class="summary" role="presentation">
  <tr><td class="k">Total applied</td><td class="v">{{ $receipt->totalApplied()->format() }}</td></tr>
  <tr><td class="k">Outstanding balance after this payment</td><td class="v">{{ $receipt->outstandingAfter->format() }}</td></tr>
  <tr><td class="k">Credit balance</td><td class="v">{{ $receipt->creditBalance->format() }}</td></tr>
</table>
