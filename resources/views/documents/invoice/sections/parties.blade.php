{{-- Billed to and Property, side by side (BRANDING_DOCUMENTS.md § 2 item 3). --}}
<table class="cols parties" role="presentation">
  <tr>
    <td class="col card" style="width:49%">
      <div class="label">Billed to</div>
      <div class="party-name">{{ $invoice->tenant->name }}</div>
      @foreach ($invoice->tenant->contactLines() as $line)
        <div class="muted small">{{ $line }}</div>
      @endforeach
    </td>
    <td class="gutter" style="width:2%"></td>
    <td class="col card" style="width:49%">
      <div class="label">Property</div>
      <div class="party-name">{{ $invoice->property->name() }}</div>
      @foreach ($invoice->property->lines() as $line)
        <div class="muted small">{{ $line }}</div>
      @endforeach
    </td>
  </tr>
</table>
