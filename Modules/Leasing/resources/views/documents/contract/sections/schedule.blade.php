{{-- Term, rent and charges schedule, deposit (BRANDING_DOCUMENTS.md § 4). Money is printed from kobo (App\Support\Money). --}}
<div class="label label-gap">Term, rent and deposit</div>
<table class="cols schedule" role="presentation">
  <tr>
    <td class="col" style="width:49%">
      <table class="kv" role="presentation">
        <tr><td class="k">Term</td><td class="v">{{ $contract->termLabel() }}</td></tr>
        <tr><td class="k">Rent</td><td class="v">{{ $contract->rent->format() }} {{ $contract->cycleLabel() }}<div class="words small">{{ $contract->rent->inWords() }}</div></td></tr>
        <tr><td class="k">Billing cycle</td><td class="v">{{ $contract->cycle->label() }}</td></tr>
      </table>
    </td>
    <td class="gutter" style="width:2%"></td>
    <td class="col" style="width:49%">
      <table class="kv" role="presentation">
        <tr><td class="k">Rent due</td><td class="v">{{ $contract->dueLabel() }}</td></tr>
        @if ($contract->paidUntilLabel())
          <tr><td class="k">Rent paid up to</td><td class="v">{{ $contract->paidUntilLabel() }}</td></tr>
        @endif
        <tr><td class="k">First rent due</td><td class="v">{{ $contract->firstDueLabel() }}</td></tr>
        <tr><td class="k">Caution deposit</td><td class="v">{{ $contract->deposit->format() }}@if ($contract->deposit->isPositive())<div class="muted small">Refundable at the end of the tenancy</div>@endif</td></tr>
      </table>
    </td>
  </tr>
</table>
