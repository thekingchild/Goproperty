{{-- Arrears aging: buckets, by building, the oldest bills. --}}
@php($buckets = \Modules\Reports\Enums\AgingBucket::cases())
<div class="report-section">
  <div class="party-name">Arrears aging</div>
  <div class="report-note">What tenants still owe on bills due on or before {{ $report->arrears->asOf->format('j M Y') }}, by days past the due date. Not yet due: {{ $report->arrears->notYetDue->format() }} on {{ number_format($report->arrears->notYetDueCount) }} {{ $report->arrears->notYetDueCount === 1 ? 'bill' : 'bills' }}.</div>
  <table class="items">
    <tr>
      <th style="width:28%">Building</th>
      @foreach ($buckets as $bucket)
        <th class="r" style="width:14%">{{ $bucket->label() }}</th>
      @endforeach
      <th class="r" style="width:16%">Total</th>
    </tr>
    @foreach ($report->arrears->buildingRows() as $index => $row)
      <tr @class(['alt' => $index % 2 === 1])>
        <td>{{ $row['name'] }}</td>
        @foreach ($buckets as $bucket)
          <td class="r nowrap">{{ $row['buckets'][$bucket->value]->format() }}</td>
        @endforeach
        <td class="r nowrap">{{ $row['total']->format() }}</td>
      </tr>
    @endforeach
    <tr class="total">
      <td>Total</td>
      @foreach ($buckets as $bucket)
        <td class="r nowrap">{{ $report->arrears->owedIn($bucket)->format() }}</td>
      @endforeach
      <td class="r nowrap">{{ $report->arrears->total()->format() }}</td>
    </tr>
  </table>
  @if ($report->arrears->lines !== [])
    <table class="items" style="margin-top:8px">
      <tr><th style="width:30%">Tenant</th><th style="width:26%">Bill</th><th style="width:14%">Due</th><th class="r" style="width:10%">Days</th><th class="r" style="width:20%">Owed</th></tr>
      @foreach (array_slice($report->arrears->lines, 0, $listed) as $index => $line)
        <tr @class(['alt' => $index % 2 === 1])>
          <td>{{ $line->bill->tenantName }}<div class="muted small">{{ $line->bill->unit !== null ? $line->bill->unit.' · ' : '' }}{{ $line->buildingName }}</div></td>
          <td class="nowrap">{{ $line->bill->number }}<div class="muted small">{{ $line->bill->typeLabel }}</div></td>
          <td class="nowrap">{{ $line->bill->dueDate->format('d/m/Y') }}</td>
          <td class="r nowrap">{{ $line->days }}</td>
          <td class="r nowrap">{{ $line->bill->outstanding->format() }}</td>
        </tr>
      @endforeach
    </table>
    @if (count($report->arrears->lines) > $listed)
      <div class="report-note">The {{ $listed }} oldest of {{ number_format(count($report->arrears->lines)) }} bills; the export lists them all.</div>
    @endif
  @endif
</div>
