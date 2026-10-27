{{-- Footer (BRANDING_DOCUMENTS.md § 2 item 9): "Generated with …" unless the plan lets the organization hide it. --}}
<div class="footer">
  <table><tr>
    <td>{{ $line }}</td>
    @if ($brand->showPoweredBy)
      <td style="text-align:right">Generated with {{ $brand->productName }} · {{ preg_replace('#^https?://#', '', $verifyUrl) }}</td>
    @endif
  </tr></table>
</div>
