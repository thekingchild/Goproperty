{{-- Received from and Payment details (BRANDING_DOCUMENTS.md § 3 items 3–4). --}}
<table class="cols" role="presentation">
  <tr>
    <td class="col" style="width:48%">
      <div class="label label-gap-sm">Received from</div>
      <table class="kv" role="presentation">
        @foreach (array_filter(['Tenant' => $receipt->tenant->name, 'Phone' => $receipt->tenant->phone, 'Property' => $receipt->property->name(), 'Lease' => $receipt->property->leaseNumber]) as $label => $value)
          <tr><td class="k">{{ $label }}</td><td class="v">{{ $value }}</td></tr>
        @endforeach
      </table>
    </td>
    <td class="gutter" style="width:4%"></td>
    <td class="col" style="width:48%">
      <div class="label label-gap-sm">Payment details</div>
      <table class="kv" role="presentation">
        @foreach ($receipt->payment->rows() as $label => $value)
          <tr><td class="k">{{ $label }}</td><td class="v">{{ $value }}</td></tr>
        @endforeach
      </table>
    </td>
  </tr>
</table>
