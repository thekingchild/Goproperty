{{-- The month's figures and the net due to the owner, with the payout record (BRANDING_DOCUMENTS.md § 4). --}}
@php($totals = $statement->totals)
<table class="cols" role="presentation">
  <tr>
    <td class="col" style="width:55%">
      <div class="label">Summary</div>
      <table class="figures" role="presentation">
        <tr><td class="k">Rent collected</td><td class="v">{{ $totals->rent->format() }}</td></tr>
        <tr><td class="k">Agency commission{{ $statement->commissionSet() ? ' ('.$statement->commissionRate().' of rent)' : ' (not set: none taken)' }}</td><td class="v">{{ $totals->commission->isZero() ? $totals->commission->format() : '− '.$totals->commission->format() }}</td></tr>
        <tr><td class="k">Utilities collected{{ $statement->utilitiesToOwner ? '' : ' (not passed to the owner)' }}</td><td class="v">{{ $statement->utilitiesToOwner ? $totals->utilities->format() : '('.$totals->utilities->format().')' }}</td></tr>
        <tr><td class="k">Expenses charged to the owner</td><td class="v">{{ $totals->expenses->isZero() ? $totals->expenses->format() : '− '.$totals->expenses->format() }}</td></tr>
        <tr class="net"><td class="k">Net due to the owner</td><td class="v">{{ $totals->netDue->format() }}</td></tr>
      </table>
    </td>
    <td class="gutter" style="width:5%"></td>
    <td class="col" style="width:40%">
      <div class="label">Payout record</div>
      <div class="payout">
        @if ($month->isSettled())
          <b>Settled.</b> Paid to the owner on {{ $month->settlement?->paid_on?->format('d/m/Y') }}, reference {{ $month->settlement?->reference }}.
        @elseif ($month->month()->hasEnded())
          <b>Not settled yet.</b> These figures are worked out from today's records and may still change until the agency marks the month settled.
        @else
          <b>Month in progress.</b> These figures are worked out from today's records and change until the month ends and is settled.
        @endif
      </div>
      <div class="muted small payout">Payments count in the month they were confirmed; a voided receipt counts, negative, in the month it was voided. The agency pays the owner outside GoProperty.</div>
    </td>
  </tr>
</table>
