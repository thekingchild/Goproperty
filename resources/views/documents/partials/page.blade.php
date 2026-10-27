{{--
    The page every document view extends (BRANDING_DOCUMENTS.md § 5):
    head with the shared styles, the footer (in the PDF's bottom margin on
    every page, once at the bottom of web pages), the brand bar and, on void
    documents, the diagonal VOID watermark. Views fill section "content".

    Data: $brand, $target, $pageCount (DocumentRenderer), and from the view:
    $docTitle (<title>), $footerLine ("company · Invoice INV-…"), $void (bool).
    A document with styles of its own (e.g. a module's lease contract) puts
    them in section "document-styles": a <style> element, after the shared
    styles and under the same rules (table layouts, simple CSS).
--}}
@php
    $target ??= \App\Documents\RenderTarget::Web;
    $pageCount ??= 1;
    $verifyUrl = url('/verify');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $docTitle }}</title>
@include('documents.partials.styles', ['brand' => $brand, 'target' => $target])
@yield('document-styles')
</head>
<body class="layout-{{ $brand->layout()->value }}">
@include('documents.partials.footer', ['brand' => $brand, 'target' => $target, 'pageCount' => $pageCount, 'line' => $footerLine, 'verifyUrl' => $verifyUrl, 'at' => 'start'])
<div class="page">
  @if ($void ?? false)
    @include('documents.partials.void-watermark')
  @endif
  @include('documents.partials.brand-bar')
  <div class="wrap">
@yield('content')
  </div>
  @include('documents.partials.footer', ['brand' => $brand, 'target' => $target, 'pageCount' => $pageCount, 'line' => $footerLine, 'verifyUrl' => $verifyUrl, 'at' => 'end'])
</div>
</body>
</html>
