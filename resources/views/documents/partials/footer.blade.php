{{--
    Footer (BRANDING_DOCUMENTS.md § 2 item 9): company name and document
    number ($line), "page x of y" on PDFs, and "Generated with …" unless the
    plan lets the organization hide it. In the PDF's bottom margin on every
    page: dompdf prints this fixed table there (its page counter, and the
    total from DocumentRenderer's first pass, $pageCount); Chrome prints the
    same text from @page margin boxes, which count pages natively. Web pages
    show it once, without page numbers.

    Include it twice: with 'at' => 'start' right after <body> (PDFs: dompdf
    repeats a fixed element only on the pages from where it appears), and
    with 'at' => 'end' at the bottom of the page (web pages).
--}}
@php
    $target ??= \App\Documents\RenderTarget::Web;
    $pageCount ??= 1;
    $here = ($at ?? 'end') === 'start' ? $target->isPdf() : ! $target->isPdf();
    $generated = $brand->showPoweredBy ? 'Generated with '.$brand->productName.' · '.preg_replace('#^https?://#', '', $verifyUrl) : null;
@endphp
@if (! $here)
@elseif ($target === \App\Documents\RenderTarget::Chrome)
<style>
  @page {
    @bottom-left { content: {!! \App\Documents\DocumentCss::string($line.' · Page ') !!} counter(page) {!! \App\Documents\DocumentCss::string(' of ') !!} counter(pages); padding-left: 48px; font-family: {!! \App\Documents\DocumentFonts::SANS_STACK !!}; font-size: 9.5px; color: #94a3b8; vertical-align: middle; }
@if ($generated !== null)
    @bottom-right { content: {!! \App\Documents\DocumentCss::string($generated) !!}; padding-right: 48px; font-family: {!! \App\Documents\DocumentFonts::SANS_STACK !!}; font-size: 9.5px; color: #94a3b8; text-align: right; vertical-align: middle; }
@endif
  }
</style>
@else
<div class="footer">
  <table role="presentation"><tr>
    <td>{{ $line }}@if ($target === \App\Documents\RenderTarget::Dompdf) · Page <span class="page-number"></span> of {{ $pageCount }}@endif</td>
    @if ($generated !== null)
      <td style="text-align:right">{{ $generated }}</td>
    @endif
  </tr></table>
</div>
@endif
