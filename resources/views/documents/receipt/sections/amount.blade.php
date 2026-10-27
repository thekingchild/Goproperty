{{--
    Amount received in figures and words with the PAID stamp (BRANDING_DOCUMENTS.md
    § 3 item 2); a voided receipt shows VOID instead (§ 3).
--}}
<div class="hero">
  <table role="presentation"><tr>
    <td>
      <div class="label">Amount received</div>
      <div class="amount">{{ $receipt->amount->format() }}</div>
      <div class="words">{{ $receipt->amount->inWords() }}</div>
    </td>
    <td class="stamp-cell">
      @if ($receipt->isVoid())
        <span class="paid-stamp void-stamp">VOID</span>
      @else
        <span class="paid-stamp">PAID</span>
      @endif
    </td>
  </tr></table>
</div>
