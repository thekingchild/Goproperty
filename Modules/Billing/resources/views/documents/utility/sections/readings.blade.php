{{--
    Meter readings billed (BRANDING_DOCUMENTS.md § 4 "Utility bill"): for
    each reading, the day read, the previous and current values, the
    consumption, the rate and the amount. On phones the previous and current
    values move under the date.
--}}
<table class="items readings">
  <tr>
    <th style="width:18%">Read on</th>
    <th class="r wide-only" style="width:16%">Previous</th>
    <th class="r wide-only" style="width:16%">Current</th>
    <th class="r" style="width:16%">Used ({{ $utilityBill->unit }})</th>
    <th class="r" style="width:16%">Rate</th>
    <th class="r" style="width:18%">Amount</th>
  </tr>
  @foreach ($utilityBill->readings as $index => $reading)
    <tr @class(['alt' => $index % 2 === 1])>
      <td><div class="nowrap">{{ $reading->readOnLabel() }}</div>@if ($reading->meterReplaced)<div class="muted small">New meter</div>@endif<div class="narrow-only muted small">{{ $reading->previous }} to {{ $reading->current }}</div></td>
      <td class="r wide-only nowrap">{{ $reading->previous }}</td>
      <td class="r wide-only nowrap">{{ $reading->current }}</td>
      <td class="r nowrap">{{ $reading->consumption }}</td>
      <td class="r nowrap">{{ $reading->rate->format() }}</td>
      <td class="r nowrap">{{ $reading->amount->format() }}</td>
    </tr>
  @endforeach
</table>
<div class="muted small readings-note">Used = current − previous reading. A replaced meter counts from the new meter's first value. Amount = used × rate per {{ $utilityBill->unit }}, to the nearest kobo.</div>
