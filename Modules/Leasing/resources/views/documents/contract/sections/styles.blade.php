{{--
    The lease contract's own styles, after the shared document styles
    (documents.partials.styles; BRANDING_DOCUMENTS.md § 5): table layouts,
    hex colours and simple CSS only, font weights 400/600/700/800.
--}}
@php
    $target ??= \App\Documents\RenderTarget::Web;
    $isCompact = $brand->layout() === \App\Documents\DocumentLayout::Compact;
    $base = $brand->layout()->baseFontSize();
@endphp
<style>
@if ($target->isPdf())
  /* A contract runs onto more pages: they start below a top margin; the first keeps its brand bar at the edge. */
  @page { margin: 40px 0 {{ \App\Documents\DocumentCss::FOOTER_HEIGHT }}px 0; }
  @page :first { margin-top: 0; }
@endif
  .doc-title-contract { font-size: {{ $isCompact ? 18 : ($brand->layout() === \App\Documents\DocumentLayout::Classic ? 18 : 22) }}px; letter-spacing: 2px; }
  .parties .party-name { font-size: {{ $base + 1 }}px; }
  .schedule .kv td.k { width: 38%; }
  .schedule .words { font-weight: 400; color: #334155; font-style: italic; margin-top: 1px; }
  table.clauses { border-collapse: separate; border-spacing: 0; }
  .clauses td { padding: {{ $isCompact ? 3 : 4 }}px 0; vertical-align: top; }
  .clauses td.clause-num { width: 26px; font-weight: 700; color: {{ $brand->strong() }}; }
  .clause-text { line-height: 1.45; color: #1e293b; }
  .keep-together { page-break-inside: avoid; }
  .signatures .sig-line { width: 100%; margin-top: 44px; }
  .signatures .sig-line-tight { margin-top: 4px; }
  table.fill { margin-top: 6px; border-collapse: separate; border-spacing: 0; }
  .fill td { padding-top: 12px; font-size: {{ $base - 1.5 }}px; color: #64748b; }
  .fill td.k { width: 56px; }
  .fill td.line { border-bottom: 1px solid #cbd5e1; }
</style>
