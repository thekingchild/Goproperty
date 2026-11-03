{{-- Per-building performance. --}}
<div class="report-section">
  <div class="party-name">Building performance</div>
  <div class="report-note">Billed, collected and spent in the period; occupancy and what is owed past due as of today.</div>
  <table class="items">
    <tr><th style="width:22%">Building</th><th class="r" style="width:9%">Occ.</th><th class="r" style="width:13%">Billed</th><th class="r" style="width:13%">Collected</th><th class="r" style="width:8%">Rate</th><th class="r" style="width:12%">Owed</th><th class="r" style="width:11%">Expenses</th><th class="r" style="width:12%">Net</th></tr>
    @foreach ($report->buildings->lines as $index => $line)
      <tr @class(['alt' => $index % 2 === 1])>
        <td>{{ $line->name }}</td>
        <td class="r">{{ $line->units->total() > 0 ? $line->units->occupancyPercent().'%' : '—' }}</td>
        <td class="r nowrap">{{ $line->flows->billedTotal()->format() }}</td>
        <td class="r nowrap">{{ $line->flows->collectedTotal()->format() }}</td>
        <td class="r nowrap">{{ \Modules\Reports\Reports\Rate::labelOf($line->flows->rate()) }}</td>
        <td class="r nowrap">{{ $line->arrears->format() }}</td>
        <td class="r nowrap">{{ $line->expenses->format() }}</td>
        <td class="r nowrap">{{ $line->net()->format() }}</td>
      </tr>
    @endforeach
  </table>
  <div class="report-note">Net: collected less expenses.</div>
</div>
