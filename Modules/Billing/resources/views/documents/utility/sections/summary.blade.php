{{-- The utility, how it charges and the period billed (BRANDING_DOCUMENTS.md § 4 "Utility bill"). --}}
<table class="cols utility-summary" role="presentation">
  <tr>
    <td class="col" style="width:40%"><div class="label">Utility</div><div class="party-name">{{ $utilityBill->utility }}</div></td>
    <td class="col" style="width:30%"><div class="label">Charged</div><div>{{ $utilityBill->charging }}</div></td>
    <td class="col" style="width:30%"><div class="label">Period</div><div class="nowrap">{{ $invoice->period?->label() }}</div></td>
  </tr>
</table>
