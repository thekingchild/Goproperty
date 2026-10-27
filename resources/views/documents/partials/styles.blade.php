{{--
    Document styles (BRANDING_DOCUMENTS.md § 5): table layouts, hex colours
    and simple CSS only (no flexbox, grid or CSS variables; font weights
    400/600/700/800 only, the bundled faces), so dompdf and Chrome render the
    same page. Sizes and fonts per layout come from App\Documents\DocumentLayout,
    the organization's colours from App\Documents\DocumentBranding ($brand).
    $target (App\Documents\RenderTarget): where the bundled fonts load from;
    PDFs reserve the bottom margin for the footer band. Shared by every document;
    tests/Feature/Documents/DocumentViewsLintTest.php checks the rules.
--}}
@php
    $target ??= \App\Documents\RenderTarget::Web;
    $layout = $brand->layout();
    $isCompact = $layout === \App\Documents\DocumentLayout::Compact;
    $base = $layout->baseFontSize();
    $fonts = \App\Documents\DocumentFonts::class;
    $headingStack = $layout->headingFontStack();
    $headingFont = $fonts::stack($headingStack, $target);
    $sansFont = $fonts::stack($fonts::SANS_STACK, $target);
    $monoFont = $fonts::stack($fonts::MONO_STACK, $target);
    // Lines set in DejaVu need dompdf's line-height scaled (DocumentFonts::lineHeight()).
    $headingLine = $fonts::lineHeight(1.3, $headingStack, $target);
    $monoLine = $fonts::lineHeight(1.45, $fonts::MONO_STACK, $target);
    $footer = \App\Documents\DocumentCss::FOOTER_HEIGHT;
@endphp
<style>
{!! \App\Documents\DocumentFonts::css($target, $layout) !!}
@if ($target->isPdf())
  /* The footer band sits in the bottom margin, on every page (partials/footer). */
  @page { size: A4; margin: 0 0 {{ $footer }}px 0; }
@else
  @page { size: A4; margin: 0; }
@endif
  * { box-sizing: border-box; }
  body { margin: 0; background: #ffffff; font-family: {!! $sansFont !!}; color: #0f172a; font-size: {{ $base }}px; line-height: 1.45; }
@if ($target->isPdf())
  .page { width: {{ \App\Documents\DocumentCss::PAGE_WIDTH }}px; min-height: {{ \App\Documents\DocumentCss::PAGE_HEIGHT - $footer }}px; margin: 0 auto; background: #ffffff; position: relative; }
@else
  .page { width: 100%; max-width: {{ \App\Documents\DocumentCss::PAGE_WIDTH }}px; margin: 0 auto; background: #ffffff; position: relative; }
@endif
  .brand-bar td { height: {{ $isCompact ? 6 : 8 }}px; background: {{ $brand->primary() }}; padding: 0; font-size: 0; line-height: 0; }
  .wrap { padding: {{ $isCompact ? '20px 40px 24px 40px' : '28px 48px 28px 48px' }}; }
  table { border-collapse: collapse; width: 100%; }
  /* Chrome ignores padding on a table in the collapsing border model (dompdf does not): boxed tables use the separate model. */
  table.verify, table.letterhead { border-collapse: separate; border-spacing: 0; }
  td, th { vertical-align: top; padding: 0; }
  .muted { color: #64748b; }
  .small { font-size: {{ $base - 1.5 }}px; }
  .label { font-size: {{ $base - 2.5 }}px; letter-spacing: 1.2px; text-transform: uppercase; color: #64748b; font-weight: 600; }
  .org-name { font-family: {!! $headingFont !!}; line-height: {{ $headingLine }}; font-size: {{ $isCompact ? 16 : 19 }}px; font-weight: 700; color: #0f172a; margin: 0 0 4px 0; }
  .legal-name { font-size: {{ $base - 1.5 }}px; color: #334155; margin: 0 0 2px 0; }
  .doc-title { font-family: {!! $headingFont !!}; line-height: {{ $headingLine }}; font-size: {{ $isCompact ? 22 : 30 }}px; font-weight: 800; letter-spacing: 3px; color: {{ $brand->strong() }}; text-align: right; margin: 0; }
  .doc-title-receipt { font-size: {{ $isCompact ? 18 : 24 }}px; }
  .monogram { width: 54px; height: 54px; background: {{ $brand->strong() }}; color: #ffffff; text-align: center; vertical-align: middle; font-family: {!! $headingFont !!}; font-size: 20px; font-weight: 800; border-radius: {{ $layout->radius(12) }}px; border-bottom: 5px solid {{ $brand->accent() }}; }
  .logo { max-width: 180px; max-height: 64px; }
  .meta td { padding: 3px 0; font-size: {{ $base - 0.5 }}px; }
  .meta td.k { color: #64748b; text-align: right; padding-right: 12px; }
  .meta td.v { text-align: right; font-weight: 600; }
  .badge { display: inline-block; padding: 4px 12px; border-radius: 999px; font-size: 10px; font-weight: 700; letter-spacing: 1px; }
  .badge-unpaid { background: #fef3c7; color: #92400e; }
  .rule { height: 1px; background: #e2e8f0; margin: {{ $isCompact ? 12 : 18 }}px 0; }
  .card { background: {{ $brand->tint() }}; border-radius: {{ $layout->radius(10) }}px; padding: {{ $isCompact ? '8px 12px' : '12px 16px' }}; @if ($layout === \App\Documents\DocumentLayout::Classic) border: 1px solid #cbd5e1; @endif }
  .party-name { font-family: {!! $headingFont !!}; line-height: {{ $headingLine }}; font-size: {{ $base + 2 }}px; font-weight: 700; margin: 4px 0 2px 0; }
  .items th { background: {{ $brand->strong() }}; color: #ffffff; font-size: {{ $base - 2 }}px; letter-spacing: 1px; text-transform: uppercase; font-weight: 600; padding: {{ $isCompact ? '7px 10px' : '10px 12px' }}; text-align: left; }
  .items th.r, .items td.r { text-align: right; }
  .items td { padding: {{ $isCompact ? '5px 10px' : '8px 12px' }}; border-bottom: 1px solid #e2e8f0; font-size: {{ $base }}px; }
  .items tr.alt td { background: #fafbfc; }
  .item-desc { font-weight: 600; }
  .totals td { padding: 4px 12px; font-size: {{ $base }}px; }
  .totals td.k { color: #64748b; text-align: right; }
  .totals td.v { text-align: right; width: 150px; font-weight: 600; }
  .due-box { background: {{ $brand->strong() }}; color: #ffffff; border-radius: {{ $layout->radius(10) }}px; padding: {{ $isCompact ? '10px 12px' : '14px 16px' }}; }
  .due-box .label { color: {{ $brand->onStrongMuted() }}; }
  .due-amount { font-size: {{ $isCompact ? 20 : 26 }}px; font-weight: 800; margin-top: 2px; }
  .due-by { text-align: right; vertical-align: middle; font-size: 11px; color: {{ $brand->onStrongMuted() }}; }
  .pay td { padding: 4px 0; font-size: {{ $base }}px; }
  .pay td.k { color: #64748b; width: 120px; }
  .pay td.v { font-weight: 600; }
  .ref { font-family: {!! $monoFont !!}; line-height: {{ $monoLine }}; font-size: 15px; font-weight: 700; letter-spacing: 2px; color: {{ $brand->strong() }}; background: #ffffff; border: 1.5px dashed {{ $brand->strong() }}; border-radius: 6px; padding: 5px 10px; display: inline-block; }
  .online { border: 1px solid #e2e8f0; border-radius: {{ $layout->radius(10) }}px; padding: 14px 16px; }
  .btn { display: inline-block; background: {{ $brand->strong() }}; color: #ffffff; text-decoration: none; font-weight: 700; padding: 8px 14px; border-radius: 6px; font-size: 11.5px; }
  .notes { font-size: {{ $base - 1.5 }}px; color: #475569; }
  .sig-image { max-width: 160px; max-height: 60px; }
  .stamp-image { max-width: 90px; max-height: 90px; }
  .sig-line { border-top: 1px solid #94a3b8; width: 200px; margin-top: 24px; padding-top: 4px; }
  .hero { background: {{ $brand->tint() }}; border-radius: {{ $layout->radius(12) }}px; padding: {{ $isCompact ? '14px 18px' : '22px 24px' }}; border-left: 6px solid {{ $brand->strong() }}; }
  .paid-stamp { display: inline-block; border: 3px solid #15803d; color: #15803d; font-weight: 800; letter-spacing: 4px; font-size: 18px; padding: 6px 16px; border-radius: 8px; }
  .amount { font-size: {{ $isCompact ? 26 : 34 }}px; font-weight: 800; color: #0f172a; margin: 4px 0 2px 0; }
  .words { font-style: italic; color: #334155; font-size: {{ $base }}px; }
  .kv td { padding: {{ $isCompact ? 4 : 7 }}px 0; border-bottom: 1px solid #f1f5f9; font-size: {{ $base }}px; }
  .kv td.k { color: #64748b; width: 42%; }
  .kv td.v { font-weight: 600; }
  .chip { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 9.5px; font-weight: 700; background: #dcfce7; color: #166534; letter-spacing: .5px; }
  .summary td { padding: 6px 12px; font-size: {{ $base }}px; }
  .summary td.k { text-align: right; color: #64748b; }
  .summary td.v { text-align: right; width: 150px; font-weight: 600; }
  .verify { border: 1px solid #e2e8f0; border-radius: {{ $layout->radius(10) }}px; padding: 14px 16px; }
  .verify-qr { width: 95px; text-align: right; vertical-align: middle; }
  .qr { width: 87px; height: 87px; }
  .code { font-family: {!! $monoFont !!}; line-height: {{ $monoLine }}; font-weight: 700; font-size: 15px; letter-spacing: 2px; color: {{ $brand->strong() }}; }
  .highlight { color: {{ $brand->onAccent() }}; background: {{ $brand->accent() }}; padding: 1px 6px; border-radius: 4px; }
  .footer td { font-size: 9.5px; color: #94a3b8; vertical-align: middle; }
@if ($target === \App\Documents\RenderTarget::Dompdf)
  /* Fixed in the bottom margin: dompdf prints it on every page. Chrome uses the @page margin boxes instead. */
  .footer { position: fixed; left: 0; right: 0; bottom: -{{ $footer }}px; height: {{ $footer }}px; }
  .footer table { height: {{ $footer }}px; }
  .footer td { padding: 0 48px; }
  .page-number:after { content: counter(page); }
@elseif ($target === \App\Documents\RenderTarget::Web)
  .footer { border-top: 1px solid #e2e8f0; }
  .footer td { padding: 12px {{ $isCompact ? 40 : 48 }}px; }
@endif
@if ($layout === \App\Documents\DocumentLayout::Classic)
  .letterhead { border: 1.5px solid {{ $brand->strong() }}; padding: 14px 16px; }
@endif
</style>
