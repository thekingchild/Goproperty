{{-- Collections vs billed, by month and by kind of bill. --}}
<div class="report-section">
  <div class="party-name">Collections vs billed</div>
  <div class="report-note">Bills issued in the period (void bills left out); money that settled bills in it: payments when confirmed, tenant credit when used, voided receipts taken back.</div>
  <table class="items">
    <tr><th style="width:40%">Month</th><th class="r" style="width:22%">Billed</th><th class="r" style="width:22%">Collected</th><th class="r" style="width:16%">Rate</th></tr>
    @foreach ($report->collections->months as $index => $month)
      <tr @class(['alt' => $index % 2 === 1])>
        <td>{{ $month->month->label }}</td>
        <td class="r nowrap">{{ $month->totals->billedTotal()->format() }}</td>
        <td class="r nowrap">{{ $month->totals->collectedTotal()->format() }}</td>
        <td class="r nowrap">{{ \Modules\Reports\Reports\Rate::labelOf($month->totals->rate()) }}</td>
      </tr>
    @endforeach
    @foreach (\Modules\Reports\Reports\FlowTotals::GROUPS as $group => $label)
      <tr>
        <td class="muted">{{ $label }}</td>
        <td class="r nowrap">{{ $report->collections->totals->billed[$group]->format() }}</td>
        <td class="r nowrap">{{ $report->collections->totals->collected[$group]->format() }}</td>
        <td class="r nowrap">{{ \Modules\Reports\Reports\Rate::labelOf($report->collections->totals->rateOf($group)) }}</td>
      </tr>
    @endforeach
    <tr class="total">
      <td>Total</td>
      <td class="r nowrap">{{ $report->collections->totals->billedTotal()->format() }}</td>
      <td class="r nowrap">{{ $report->collections->totals->collectedTotal()->format() }}</td>
      <td class="r nowrap">{{ \Modules\Reports\Reports\Rate::labelOf($report->collections->totals->rate()) }}</td>
    </tr>
  </table>
</div>
