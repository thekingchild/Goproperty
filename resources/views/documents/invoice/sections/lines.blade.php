{{-- Line items: description and note, period, amount (BRANDING_DOCUMENTS.md § 2 item 4). --}}
<table class="items">
  <tr>
    <th style="width:46%">Description</th>
    <th class="wide-only" style="width:30%">Period</th>
    <th class="r" style="width:24%">Amount</th>
  </tr>
  @foreach ($invoice->lines as $index => $line)
    @php($period = $line->periodLabel($invoice->period))
    <tr @class(['alt' => $index % 2 === 1])>
      <td><div class="item-desc">{{ $line->description }}</div>@if ($line->note)<div class="muted small">{{ $line->note }}</div>@endif<div class="narrow-only muted small">{{ $period }}</div></td>
      <td class="wide-only">{{ $period }}</td>
      <td class="r nowrap">{{ $line->amount->format() }}</td>
    </tr>
  @endforeach
</table>
