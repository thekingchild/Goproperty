{{-- Notes (the agency's terms; on a void invoice, why it was voided) beside the verify box (BRANDING_DOCUMENTS.md § 2 items 7–8). --}}
<table class="cols closing" role="presentation">
  <tr>
    <td class="col notes" style="width:65%">
      @if ($invoice->isVoid())
        {{-- A void invoice asks for nothing: why it was voided replaces the agency's terms. --}}
        <div class="void-notice"><b>This invoice is void: nothing is due on it.</b>@if ($invoice->voidReason) Reason: {{ $invoice->voidReason }}@endif</div>
      @elseif ($brand->branding->invoiceNotes)
        <div class="label label-gap-sm">Notes</div>
        <div class="notes-text">{!! nl2br(e($brand->branding->invoiceNotes)) !!}</div>
      @endif
    </td>
    <td class="gutter" style="width:3%"></td>
    <td class="col" style="width:32%">
      @include('documents.partials.verify-box', ['code' => $invoice->verificationCode, 'label' => 'Verify this invoice', 'boxed' => false])
    </td>
  </tr>
</table>
