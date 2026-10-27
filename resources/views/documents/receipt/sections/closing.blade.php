{{-- Confirmed by, signature/stamp line and the verify box (BRANDING_DOCUMENTS.md § 3 items 6–7), then the electronic-issue statement (item 8), or on a voided receipt when and why it was voided. --}}
<table class="cols" role="presentation">
  <tr>
    <td class="col" style="width:29%">
      <div class="label">Confirmed by</div>
      <div class="confirmed-by">{{ $receipt->confirmedBy }}</div>
      <div class="small muted">{{ $receipt->confirmedAtLabel() }}</div>
    </td>
    <td class="gutter" style="width:2%"></td>
    <td class="col sign-col" style="width:26%">
      @include('documents.partials.signature', ['brand' => $brand, 'line' => 'Authorised signature / stamp'])
    </td>
    <td class="gutter" style="width:2%"></td>
    <td class="col" style="width:41%">
      @include('documents.partials.verify-box', ['code' => $receipt->verificationCode, 'label' => 'Verify this receipt'])
    </td>
  </tr>
</table>
@if ($receipt->isVoid())
  {{-- A voided receipt proves nothing: when and why it was voided replace the statement and the footer. --}}
  <div class="void-notice block"><b>This receipt was voided on {{ $receipt->voidedAtLabel() }} and is not proof of payment.</b> Reason: {{ $receipt->voidReason }}</div>
@else
<div class="small muted block">This receipt was issued electronically and is valid without a signature.@if ($receipt->sentSentence()) {{ $receipt->sentSentence() }}@endif</div>
@if ($brand->branding->receiptFooter)
  <div class="small muted receipt-footer">{!! nl2br(e($brand->branding->receiptFooter)) !!}</div>
@endif
@endif
