{{--
    Letterhead (BRANDING_DOCUMENTS.md § 2 item 2, § 3 item 1): logo or
    monogram, name, address, RC number, phone, email, website on the left;
    the document's title and meta rows ($meta: label => value) on the right.
--}}
<table class="letterhead">
  <tr>
    <td style="width:58%">
      <table><tr>
        <td style="width:{{ $brand->logoSrc ? 'auto' : '64px' }}; padding-right:12px">
          @if ($brand->logoSrc)
            <img class="logo" src="{{ $brand->logoSrc }}" alt="{{ $brand->name() }}">
          @else
            <table style="width:54px"><tr><td class="monogram">{{ $brand->initials() }}</td></tr></table>
          @endif
        </td>
        <td>
          <p class="org-name">{{ $brand->name() }}</p>
          @if ($brand->legalName())
            <p class="legal-name">{{ $brand->legalName() }}</p>
          @endif
          @foreach ($brand->letterheadLines() as $line)
            <div class="muted small">{{ $line }}</div>
          @endforeach
        </td>
      </tr></table>
    </td>
    <td style="width:42%">
      <p class="doc-title {{ $titleClass ?? '' }}">{{ $title }}</p>
      <table class="meta" style="margin-top:8px">
        @foreach ($meta as $label => $value)
          <tr><td class="k">{{ $label }}</td><td class="v">{!! $value !!}</td></tr>
        @endforeach
      </table>
    </td>
  </tr>
</table>
