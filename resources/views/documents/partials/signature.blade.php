{{-- The agency's signature and stamp images, when uploaded (BRANDING_DOCUMENTS.md § 1), above a signature line. --}}
@if ($brand->signatureSrc || $brand->stampSrc)
  <table class="sig-images" role="presentation"><tr>
    @if ($brand->signatureSrc)<td class="sig-cell"><img class="sig-image" src="{{ $brand->signatureSrc }}" alt="Signature"></td>@endif
    @if ($brand->stampSrc)<td class="sig-cell"><img class="stamp-image" src="{{ $brand->stampSrc }}" alt="Stamp"></td>@endif
  </tr></table>
@endif
<div @class(['sig-line small muted', 'sig-line-tight' => $brand->signatureSrc || $brand->stampSrc])>{{ $line }}</div>
