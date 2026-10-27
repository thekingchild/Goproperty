{{--
    Signature blocks for the landlord (signed by the agency, with its
    signature and stamp images when uploaded: BRANDING_DOCUMENTS.md § 1) and
    the tenant, each with a witness; then the electronic-issue statement and
    the verify box (§ 5: the QR code opens /verify/{code}).
--}}
<div class="label label-gap">Signed by the parties</div>
<table class="cols signatures" role="presentation">
  <tr>
    <td class="col sign-block" style="width:49%">
      <div class="small muted">For the landlord, {{ $contract->landlordName() }}@if ($contract->managedForOwner()), by {{ $brand->name() }} as managing agent @endif</div>
      @include('documents.partials.signature', ['brand' => $brand, 'line' => 'Signature and stamp'])
      <table class="fill" role="presentation">
        <tr><td class="k">Name</td><td class="line"></td></tr>
        <tr><td class="k">Date</td><td class="line"></td></tr>
        <tr><td class="k">Witness</td><td class="line"></td></tr>
      </table>
    </td>
    <td class="gutter" style="width:2%"></td>
    <td class="col sign-block" style="width:49%">
      <div class="small muted">The tenant, {{ $contract->tenant->name }}</div>
      <div class="sig-line small muted">Signature</div>
      <table class="fill" role="presentation">
        <tr><td class="k">Name</td><td class="line"></td></tr>
        <tr><td class="k">Date</td><td class="line"></td></tr>
        <tr><td class="k">Witness</td><td class="line"></td></tr>
      </table>
    </td>
  </tr>
</table>
<table class="cols closing block" role="presentation">
  <tr>
    <td class="col notes" style="width:62%">
      <div class="notes-text">This agreement was issued electronically by {{ $brand->name() }} on {{ $contract->issuedOnLabel() }} as contract {{ $contract->number }}. Scan the code or enter it on the verify page to confirm that this copy is genuine and whether the lease is still in force.</div>
    </td>
    <td class="gutter" style="width:3%"></td>
    <td class="col" style="width:35%">
      @include('documents.partials.verify-box', ['code' => $contract->verificationCode, 'label' => 'Verify this agreement', 'boxed' => false])
    </td>
  </tr>
</table>
