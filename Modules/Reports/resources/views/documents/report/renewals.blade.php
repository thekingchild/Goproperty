{{-- Upcoming renewals. --}}
<div class="report-section">
  <div class="party-name">Upcoming renewals</div>
  <div class="report-note">Active leases ending by {{ $report->renewals->until->format('j M Y') }} (the next {{ $report->renewals->days }} days), soonest first.</div>
  <table class="items">
    <tr><th style="width:34%">Tenant</th><th style="width:16%">Ends</th><th class="r" style="width:22%">Rent</th><th style="width:28%">Renewal</th></tr>
    @forelse (array_slice($report->renewals->lines, 0, $listed) as $index => $line)
      <tr @class(['alt' => $index % 2 === 1])>
        <td>{{ $line->tenantName }}<div class="muted small">{{ $line->unitLabel !== '' ? $line->unitLabel.' · ' : '' }}{{ $line->buildingName }}</div></td>
        <td class="nowrap">{{ $line->endDate->format('d/m/Y') }}<div class="muted small">in {{ $line->daysLeft }} {{ $line->daysLeft === 1 ? 'day' : 'days' }}</div></td>
        <td class="r nowrap">{{ $line->rent->format() }}<div class="muted small">{{ $line->cycleLabel }}</div></td>
        <td>{{ $line->renewalLabel }}</td>
      </tr>
    @empty
      <tr><td class="muted" colspan="4">No active lease ends in the next {{ $report->renewals->days }} days.</td></tr>
    @endforelse
  </table>
  @if (count($report->renewals->lines) > $listed)
    <div class="report-note">The {{ $listed }} soonest of {{ number_format(count($report->renewals->lines)) }} leases; the export lists them all.</div>
  @endif
</div>
