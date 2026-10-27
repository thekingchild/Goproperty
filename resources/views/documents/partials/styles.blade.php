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
    // Space between the blocks of invoices and receipts.
    $gap = $isCompact ? 12 : 14;
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
  .wrap { padding: {{ $isCompact ? '20px 40px' : '24px 48px' }} {{ $target->isPdf() ? 8 : 28 }}px {{ $isCompact ? 40 : 48 }}px; }
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
  .meta td { padding: 2px 0; font-size: {{ $base - 0.5 }}px; }
  .meta td.k { color: #64748b; text-align: right; padding-right: 12px; }
  .meta td.v { text-align: right; font-weight: 600; }
  /* Pills set their own line height, so a line holding one is as tall in Chrome as in dompdf. */
  .badge { display: inline-block; padding: 4px 12px; border-radius: 999px; font-size: 10px; line-height: 1.2; font-weight: 700; letter-spacing: 1px; }
  .badge-unpaid { background: #fef3c7; color: #92400e; }
  .rule { height: 1px; background: #e2e8f0; margin: {{ $isCompact ? 10 : 14 }}px 0; }
  .card { background: {{ $brand->tint() }}; border-radius: {{ $layout->radius(10) }}px; padding: {{ $isCompact ? '8px 12px' : '12px 16px' }}; @if ($layout === \App\Documents\DocumentLayout::Classic) border: 1px solid #cbd5e1; @endif }
  .party-name { font-family: {!! $headingFont !!}; line-height: {{ $headingLine }}; font-size: {{ $base + 2 }}px; font-weight: 700; margin: 4px 0 2px 0; }
  .items th { background: {{ $brand->strong() }}; color: #ffffff; font-size: {{ $base - 2 }}px; letter-spacing: 1px; text-transform: uppercase; font-weight: 600; padding: {{ $isCompact ? '6px 10px' : '7px 12px' }}; text-align: left; }
  .items th.r, .items td.r { text-align: right; }
  .items td { padding: {{ $isCompact ? '4px 10px' : '5px 12px' }}; border-bottom: 1px solid #e2e8f0; font-size: {{ $base }}px; }
  .items tr.alt td { background: #fafbfc; }
  .item-desc { font-weight: 600; line-height: 1.35; }
  .items td .small { line-height: 1.3; }
  .totals td { padding: 3px 12px; font-size: {{ $base }}px; }
  .totals td.k { color: #64748b; text-align: right; }
  /* Fixed widths of padded cells count the content only, as dompdf reads them. */
  .totals td.v { text-align: right; width: 126px; box-sizing: content-box; font-weight: 600; }
  .due-box { background: {{ $brand->strong() }}; color: #ffffff; border-radius: {{ $layout->radius(10) }}px; padding: {{ $isCompact ? '9px 12px' : '12px 16px' }}; }
  .due-box .label { color: {{ $brand->onStrongMuted() }}; }
  .due-amount { font-size: {{ $isCompact ? 20 : 26 }}px; font-weight: 800; margin-top: 2px; }
  .due-by { text-align: right; vertical-align: middle; font-size: 11px; color: {{ $brand->onStrongMuted() }}; }
  .pay td { padding: 4px 0; font-size: {{ $base }}px; }
  .pay td.k { color: #64748b; width: 120px; }
  .pay td.v { font-weight: 600; }
  .ref { font-family: {!! $monoFont !!}; line-height: {{ $monoLine }}; font-size: 15px; font-weight: 700; letter-spacing: 2px; color: {{ $brand->strong() }}; background: #ffffff; border: 1.5px dashed {{ $brand->strong() }}; border-radius: 6px; padding: 5px 10px; display: inline-block; }
  .btn { display: inline-block; background: {{ $brand->strong() }}; color: #ffffff; text-decoration: none; font-weight: 700; padding: 8px 14px; border-radius: 6px; font-size: 11.5px; }
  .notes { font-size: {{ $base - 1.5 }}px; color: #475569; }
  .sig-image { max-width: 150px; max-height: 40px; }
  .stamp-image { max-width: 60px; max-height: 60px; }
  .sig-line { border-top: 1px solid #94a3b8; width: 200px; margin-top: 24px; padding-top: 4px; }
  .sign-off { margin-top: 6px; }
  .sign-col .sig-line { width: 100%; margin-top: 40px; }
  .sign-col .sig-line-tight { margin-top: 4px; }
  .notes-text { line-height: 1.35; }
  .hero { background: {{ $brand->tint() }}; border-radius: {{ $layout->radius(12) }}px; padding: {{ $isCompact ? '12px 18px' : '16px 24px' }}; border-left: 6px solid {{ $brand->strong() }}; }
  .paid-stamp { display: inline-block; border: 3px solid #15803d; color: #15803d; font-weight: 800; letter-spacing: 4px; font-size: 18px; padding: 6px 16px; border-radius: 8px; }
  .amount { font-size: {{ $isCompact ? 26 : 32 }}px; font-weight: 800; color: #0f172a; margin: 4px 0 2px 0; }
  .words { font-style: italic; color: #334155; font-size: {{ $base }}px; }
  .kv td { padding: {{ $isCompact ? 3 : 5 }}px 0; border-bottom: 1px solid #f1f5f9; font-size: {{ $base }}px; }
  .kv td.k { color: #64748b; width: 42%; }
  .kv td.v { font-weight: 600; }
  .chip { display: inline-block; padding: 1px 8px; border-radius: 999px; font-size: 9.5px; line-height: 1.2; font-weight: 700; background: #dcfce7; color: #166534; letter-spacing: .5px; }
  .summary td { padding: 3px 12px; font-size: {{ $base }}px; }
  .summary td.k { text-align: right; color: #64748b; }
  .summary td.v { text-align: right; width: 126px; box-sizing: content-box; font-weight: 600; }
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
  /* dompdf does not centre text in a fixed table's cell: pad it to the middle of the band, where Chrome's margin boxes put it. */
  .footer td { padding: 13px 48px 0 48px; vertical-align: top; line-height: 1.45; }
  .page-number:after { content: counter(page); }
@elseif ($target === \App\Documents\RenderTarget::Web)
  .footer { border-top: 1px solid #e2e8f0; }
  .footer td { padding: 12px {{ $isCompact ? 40 : 48 }}px; }
@endif
@if ($layout === \App\Documents\DocumentLayout::Classic)
  .letterhead { border: 1.5px solid {{ $brand->strong() }}; padding: 14px 16px; }
@endif
  /* Invoice and receipt templates (P03-05b). */
  .block { margin-top: {{ $gap }}px; }
  table.cols { border-collapse: separate; border-spacing: 0; }
  .label-gap { margin-bottom: 6px; }
  .label-gap-sm { margin-bottom: 4px; }
  .nowrap { white-space: nowrap; }
  /* Shown only on phones (web pages), where the column they repeat is hidden. */
  .narrow-only { display: none; }
  .badge-draft { background: #e2e8f0; color: #334155; }
  .badge-partially-paid { background: #dbeafe; color: #1e40af; }
  .badge-paid { background: #dcfce7; color: #166534; }
  .badge-overdue { background: #fee2e2; color: #991b1b; }
  .badge-void { background: #1e293b; color: #ffffff; }
  .void-notice { padding: 8px 12px; border: 1px solid #fca5a5; background: #fef2f2; color: #991b1b; border-radius: {{ $layout->radius(8) }}px; font-size: {{ $base - 1 }}px; }
  .void-watermark { position: absolute; left: 0; width: {{ \App\Documents\DocumentCss::PAGE_WIDTH }}px; top: 430px; text-align: center; font-family: {!! $sansFont !!}; font-size: 190px; line-height: 1; font-weight: 800; letter-spacing: 24px; color: #dc2626; opacity: 0.14; transform: rotate(-32deg); }
  .totals { margin-top: -4px; }
  .due-box { margin-top: 8px; }
  .due-date { color: #ffffff; font-size: 13px; }
  .pay td.k.narration, .pay td.v.narration { padding-top: 5px; }
  .account-number { font-size: 14px; letter-spacing: 1px; }
  .pay-hint { margin-top: 8px; }
  table.online { margin-top: 10px; border-top: 1px solid #dbe3e8; border-collapse: separate; border-spacing: 0; }
  table.online td { padding-top: 8px; }
  .online-text { vertical-align: middle; padding-right: 10px; }
  .online-button { vertical-align: middle; text-align: right; width: 1%; white-space: nowrap; }
  .settled { font-weight: 600; font-size: {{ $base }}px; }
  .sig-images { width: auto; margin-top: 10px; }
  .sig-cell { vertical-align: bottom; padding-right: 12px; }
  .sig-line-tight { margin-top: 4px; }
  .stamp-cell { text-align: right; vertical-align: middle; width: 160px; }
  .void-stamp { border-color: #b91c1c; color: #b91c1c; }
  .confirmed-by { font-weight: 600; margin-top: 4px; }
  .receipt-footer { margin-top: 6px; }
  table.summary { margin-top: 6px; }
@if ($layout === \App\Documents\DocumentLayout::Classic)
  /* Classic: white, square, ruled panels; the boxed letterhead replaces the rule under it. */
  .letterhead { padding: 10px 14px; }
  .doc-title-receipt { font-size: 21px; letter-spacing: 2px; }
  .card, .hero { background: #ffffff; border: 1px solid #cbd5e1; }
  .hero { border-left: 6px solid {{ $brand->strong() }}; }
  .items { border: 1px solid #cbd5e1; }
  .items td { border-bottom: 1px solid #cbd5e1; }
  .items tr.alt td { background: #ffffff; }
@endif
@if ($target === \App\Documents\RenderTarget::Web)
  /* Web pages on phones (UX_DESIGN.md § 1: 360 px first): one column, no sideways scrolling. */
  img { max-width: 100%; height: auto; }
  .void-watermark { width: 100%; }
  @media (max-width: 720px) {
    .void-watermark { font-size: 80px; letter-spacing: 6px; top: 260px; }
  }
  @media (max-width: 640px) {
    .wrap { padding: 18px 16px 22px 16px; }
    .cols, .cols > tbody, .cols > tbody > tr, .cols > tr, td.col { display: block; width: 100% !important; }
    td.col + td.gutter + td.col { margin-top: 12px; }
    td.gutter { display: none; }
    .letterhead > tbody > tr > td, .letterhead > tr > td { display: block; width: 100% !important; }
    .letterhead .doc-title { text-align: left; margin-top: 14px; }
    .letterhead .meta td.k { text-align: left; }
    .wide-only { display: none; }
    .narrow-only { display: block; }
    .brand-id td { display: block; width: auto !important; padding-right: 0 !important; }
    .brand-id .logo, .brand-id .monogram { margin-bottom: 10px; }
    .hero .stamp-cell { display: block; width: auto; text-align: left; margin-top: 12px; }
    .hero > table > tbody > tr > td, .hero > table > tr > td { display: block; }
    .items th, .items td { padding-left: 8px; padding-right: 8px; }
    .doc-title { font-size: 24px; letter-spacing: 2px; }
    .amount { font-size: 28px; }
    .online-text, .online-button { display: block; width: 100%; text-align: left; padding-right: 0; }
    .online-button { margin-top: 8px; }
    .verify-qr { width: 95px; }
    .footer td { display: block; text-align: left !important; padding: 6px 16px; }
    td, div, p { overflow-wrap: anywhere; }
    .amount, .due-amount, .nowrap, .code, .ref, .badge, .chip, .paid-stamp, .btn { overflow-wrap: normal; }
  }
@endif
</style>
