{{-- The parties and the premises (BRANDING_DOCUMENTS.md § 4): landlord (and the agency managing for them), tenant, the unit let. --}}
<table class="cols parties" role="presentation">
  <tr>
    <td class="col card" style="width:32%">
      <div class="label">Landlord</div>
      <div class="party-name">{{ $contract->landlordName() }}</div>
      @if ($contract->managedForOwner())
        <div class="muted small">Represented by {{ $brand->name() }}, managing agent</div>
      @else
        @foreach (array_slice($brand->letterheadLines(), 0, 1) as $line)
          <div class="muted small">{{ $line }}</div>
        @endforeach
      @endif
    </td>
    <td class="gutter" style="width:2%"></td>
    <td class="col card" style="width:32%">
      <div class="label">Tenant</div>
      <div class="party-name">{{ $contract->tenant->name }}</div>
      @foreach ($contract->tenant->contactLines() as $line)
        <div class="muted small">{{ $line }}</div>
      @endforeach
    </td>
    <td class="gutter" style="width:2%"></td>
    <td class="col card" style="width:32%">
      <div class="label">Premises</div>
      <div class="party-name">{{ $contract->property->name() }}</div>
      @if ($contract->property->address)
        <div class="muted small">{{ $contract->property->address }}</div>
      @endif
    </td>
  </tr>
</table>
