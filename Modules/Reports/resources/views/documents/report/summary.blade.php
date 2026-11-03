{{-- The headline figures. --}}
<div class="report-section">
  <table class="tiles">
    <tr>
      <td><div class="k">Occupancy (now)</div><div class="v">{{ $report->occupancy->totals->occupancyPercent() }}%</div><div class="k">{{ number_format($report->occupancy->totals->occupied) }} of {{ number_format($report->occupancy->totals->total()) }} units</div></td>
      <td><div class="k">Billed</div><div class="v">{{ $report->collections->totals->billedTotal()->format() }}</div></td>
      <td><div class="k">Collected</div><div class="v">{{ $report->collections->totals->collectedTotal()->format() }}</div><div class="k">Rate {{ \Modules\Reports\Reports\Rate::labelOf($report->collections->totals->rate()) }}</div></td>
      <td><div class="k">Owed past due (today)</div><div class="v">{{ $report->arrears->total()->format() }}</div></td>
      <td><div class="k">Expenses</div><div class="v">{{ $report->expenses->total()->format() }}</div></td>
    </tr>
  </table>
</div>
