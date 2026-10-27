{{-- Subtotal, credit applied, paid to date and the Amount due panel (BRANDING_DOCUMENTS.md § 2 item 5). --}}
<table class="totals" role="presentation">
  <tr><td class="k">Subtotal</td><td class="v">{{ $invoice->subtotal()->format() }}</td></tr>
  <tr><td class="k">Credit applied</td><td class="v">{{ $invoice->credit->isZero() ? $invoice->credit->format() : '− '.$invoice->credit->format() }}</td></tr>
  <tr><td class="k">Paid to date</td><td class="v">{{ $invoice->paidToDate->format() }}</td></tr>
</table>
<div class="due-box">
  <table role="presentation"><tr>
    <td><div class="label">Amount due</div><div class="due-amount">{{ $invoice->amountDue()->format() }}</div></td>
    <td class="due-by">Due by<br><b class="due-date">{{ $invoice->dueOnLong() }}</b></td>
  </tr></table>
</div>
