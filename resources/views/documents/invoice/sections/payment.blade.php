{{--
    How to pay (BRANDING_DOCUMENTS.md § 2 item 6): the organization's bank
    account and the bill's payment reference for the narration, then the
    "Pay online" button and link when the module passes one (own Paystack
    connected, amount within its limit). Paid, void and draft invoices say
    so instead.
--}}
@if ($invoice->showsPayment())
  <div class="card pay-card">
    <div class="label label-gap">Pay by bank transfer</div>
    <table class="pay" role="presentation">
      @if ($invoice->bank)
        <tr><td class="k">Bank</td><td class="v">{{ $invoice->bank->bank }}</td></tr>
        <tr><td class="k">Account name</td><td class="v">{{ $invoice->bank->accountName }}</td></tr>
        <tr><td class="k">Account number</td><td class="v account-number">{{ $invoice->bank->accountNumber }}</td></tr>
      @endif
      <tr><td class="k narration">Narration</td><td class="v narration"><span class="ref">{{ $invoice->paymentReference }}</span></td></tr>
    </table>
    <div class="small muted pay-hint">Put <b>{{ $invoice->paymentReference }}</b> in the narration to have the payment matched.@unless ($invoice->bank) Ask {{ $brand->name() }} for the account to pay into.@endunless</div>
    @if ($invoice->online)
      <table class="online" role="presentation"><tr>
        <td class="online-text">
          <div class="label">Or pay online</div>
          <div class="small muted">{{ $invoice->online->shortLink() }}</div>
        </td>
        <td class="online-button"><a class="btn" href="{{ $invoice->online->url }}">Pay {{ $invoice->amountDue()->format() }} online</a></td>
      </tr></table>
    @endif
  </div>
@else
  <div class="card pay-card">
    <div class="label label-gap">Payment</div>
    <div class="settled">{{ match ($invoice->status) {
        \App\Documents\Invoice\InvoiceStatus::Paid => 'Paid in full. Thank you.',
        \App\Documents\Invoice\InvoiceStatus::Void => 'This invoice is void: nothing is due on it.',
        \App\Documents\Invoice\InvoiceStatus::Draft => 'Draft: not issued yet, do not pay.',
        default => 'Nothing is due on this invoice.',
    } }}</div>
  </div>
@endif
