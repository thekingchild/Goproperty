{{-- Occupancy now. --}}
<div class="report-section">
  <div class="party-name">Occupancy</div>
  <div class="report-note">Units by status now (archived units left out).</div>
  <table class="items">
    <tr><th style="width:36%">Building</th><th class="r" style="width:12%">Units</th><th class="r" style="width:13%">Occupied</th><th class="r" style="width:13%">Vacant</th><th class="r" style="width:12%">Other</th><th class="r" style="width:14%">Occupancy</th></tr>
    @foreach ($report->occupancy->buildings as $index => $building)
      <tr @class(['alt' => $index % 2 === 1])>
        <td>{{ $building->name }}</td>
        <td class="r">{{ number_format($building->units->total()) }}</td>
        <td class="r">{{ number_format($building->units->occupied) }}</td>
        <td class="r">{{ number_format($building->units->vacant) }}</td>
        <td class="r">{{ number_format($building->units->reserved + $building->units->underMaintenance + $building->units->unavailable) }}</td>
        <td class="r">{{ $building->units->occupancyPercent() }}%</td>
      </tr>
    @endforeach
    <tr class="total">
      <td>Total</td>
      <td class="r">{{ number_format($report->occupancy->totals->total()) }}</td>
      <td class="r">{{ number_format($report->occupancy->totals->occupied) }}</td>
      <td class="r">{{ number_format($report->occupancy->totals->vacant) }}</td>
      <td class="r">{{ number_format($report->occupancy->totals->reserved + $report->occupancy->totals->underMaintenance + $report->occupancy->totals->unavailable) }}</td>
      <td class="r">{{ $report->occupancy->totals->occupancyPercent() }}%</td>
    </tr>
  </table>
</div>
