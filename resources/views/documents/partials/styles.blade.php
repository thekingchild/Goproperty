{{--
    Document styles (BRANDING_DOCUMENTS.md § 5): table layouts, hex colours
    and simple CSS only (no flexbox, grid or CSS variables), so dompdf and
    Chrome render the same page. One stylesheet per layout (modern, classic,
    compact) over the shared base, with the organization's colours from
    App\Documents\DocumentBranding ($brand). Shared by every document.
--}}
@php
    $layout = $brand->layout()->value;
    $base = $layout === 'compact' ? 10.5 : 12;
    $headingFont = $layout === 'classic' ? '"DejaVu Serif", Georgia, "Times New Roman", serif' : 'Inter, "DejaVu Sans", Arial, sans-serif';
@endphp
<style>
  @page { size: A4; margin: 0; }
  /* A4 is 1122.5 px tall at 96 dpi: a 1123 px page would spill onto a second sheet. */
  * { box-sizing: border-box; }
  body { margin: 0; background: #ffffff; font-family: Inter, "DejaVu Sans", Arial, sans-serif; color: #0f172a; font-size: {{ $base }}px; line-height: 1.45; }
  .page { width: 794px; min-height: 1122px; margin: 0 auto; background: #ffffff; position: relative; }
  .topbar { height: {{ $layout === 'compact' ? 6 : 8 }}px; background: {{ $brand->primary() }}; }
  .wrap { padding: {{ $layout === 'compact' ? '20px 40px 50px 40px' : '28px 48px 54px 48px' }}; }
  table { border-collapse: collapse; width: 100%; }
  td, th { vertical-align: top; padding: 0; }
  .muted { color: #64748b; }
  .small { font-size: {{ $base - 1.5 }}px; }
  .label { font-size: {{ $base - 2.5 }}px; letter-spacing: 1.2px; text-transform: uppercase; color: #64748b; font-weight: 600; }
  .org-name { font-family: {!! $headingFont !!}; font-size: {{ $layout === 'compact' ? 16 : 19 }}px; font-weight: 700; color: #0f172a; margin: 0 0 4px 0; }
  .legal-name { font-size: {{ $base - 1.5 }}px; color: #334155; margin: 0 0 2px 0; }
  .doc-title { font-family: {!! $headingFont !!}; font-size: {{ $layout === 'compact' ? 22 : 30 }}px; font-weight: 800; letter-spacing: 3px; color: {{ $brand->strong() }}; text-align: right; margin: 0; }
  .doc-title-receipt { font-size: {{ $layout === 'compact' ? 18 : 24 }}px; }
  .monogram { width: 54px; height: 54px; background: {{ $brand->strong() }}; color: #ffffff; text-align: center; vertical-align: middle; font-family: {!! $headingFont !!}; font-size: 20px; font-weight: 800; border-radius: {{ $layout === 'classic' ? 0 : 12 }}px; border-bottom: 5px solid {{ $brand->accent() }}; }
  .logo { max-width: 180px; max-height: 64px; }
  .meta td { padding: 3px 0; font-size: {{ $base - 0.5 }}px; }
  .meta td.k { color: #64748b; text-align: right; padding-right: 12px; }
  .meta td.v { text-align: right; font-weight: 600; }
  .badge { display: inline-block; padding: 4px 12px; border-radius: 999px; font-size: 10px; font-weight: 700; letter-spacing: 1px; }
  .badge-unpaid { background: #fef3c7; color: #92400e; }
  .rule { height: 1px; background: #e2e8f0; margin: {{ $layout === 'compact' ? 12 : 18 }}px 0; }
  .card { background: {{ $brand->tint() }}; border-radius: {{ $layout === 'classic' ? 0 : 10 }}px; padding: {{ $layout === 'compact' ? '8px 12px' : '12px 16px' }}; @if ($layout === 'classic') border: 1px solid #cbd5e1; @endif }
  .party-name { font-family: {!! $headingFont !!}; font-size: {{ $base + 2 }}px; font-weight: 700; margin: 4px 0 2px 0; }
  .items th { background: {{ $brand->strong() }}; color: #ffffff; font-size: {{ $base - 2 }}px; letter-spacing: 1px; text-transform: uppercase; font-weight: 600; padding: {{ $layout === 'compact' ? '7px 10px' : '10px 12px' }}; text-align: left; }
  .items th.r, .items td.r { text-align: right; }
  .items td { padding: {{ $layout === 'compact' ? '5px 10px' : '8px 12px' }}; border-bottom: 1px solid #e2e8f0; font-size: {{ $base }}px; }
  .items tr.alt td { background: #fafbfc; }
  .item-desc { font-weight: 600; }
  .totals td { padding: 4px 12px; font-size: {{ $base }}px; }
  .totals td.k { color: #64748b; text-align: right; }
  .totals td.v { text-align: right; width: 150px; font-weight: 600; }
  .due-box { background: {{ $brand->strong() }}; color: #ffffff; border-radius: {{ $layout === 'classic' ? 0 : 10 }}px; padding: {{ $layout === 'compact' ? '10px 12px' : '14px 16px' }}; }
  .due-box .label { color: {{ $brand->onStrongMuted() }}; }
  .due-amount { font-size: {{ $layout === 'compact' ? 20 : 26 }}px; font-weight: 800; margin-top: 2px; }
  .due-by { text-align: right; vertical-align: middle; font-size: 11px; color: {{ $brand->onStrongMuted() }}; }
  .pay td { padding: 4px 0; font-size: {{ $base }}px; }
  .pay td.k { color: #64748b; width: 120px; }
  .pay td.v { font-weight: 600; }
  .ref { font-family: "DejaVu Sans Mono", Menlo, monospace; font-size: 15px; font-weight: 700; letter-spacing: 2px; color: {{ $brand->strong() }}; background: #ffffff; border: 1.5px dashed {{ $brand->strong() }}; border-radius: 6px; padding: 5px 10px; display: inline-block; }
  .online { border: 1px solid #e2e8f0; border-radius: {{ $layout === 'classic' ? 0 : 10 }}px; padding: 14px 16px; }
  .btn { display: inline-block; background: {{ $brand->strong() }}; color: #ffffff; text-decoration: none; font-weight: 700; padding: 8px 14px; border-radius: 6px; font-size: 11.5px; }
  .notes { font-size: {{ $base - 1.5 }}px; color: #475569; }
  .qr { width: 87px; height: 87px; border: 1px solid #cbd5e1; color: #94a3b8; font-size: 9px; text-align: center; vertical-align: middle; }
  .sig-image { max-width: 160px; max-height: 60px; }
  .stamp-image { max-width: 90px; max-height: 90px; }
  .sig-line { border-top: 1px solid #94a3b8; width: 200px; margin-top: 24px; padding-top: 4px; }
  /* Fixed, not absolute: dompdf and Chrome both print it at the foot of every page. */
  .footer { position: fixed; left: 0; right: 0; bottom: 0; padding: 14px 48px; border-top: 1px solid #e2e8f0; font-size: 9.5px; color: #94a3b8; }
  .hero { background: {{ $brand->tint() }}; border-radius: {{ $layout === 'classic' ? 0 : 12 }}px; padding: {{ $layout === 'compact' ? '14px 18px' : '22px 24px' }}; border-left: 6px solid {{ $brand->strong() }}; }
  .paid-stamp { display: inline-block; border: 3px solid #15803d; color: #15803d; font-weight: 800; letter-spacing: 4px; font-size: 18px; padding: 6px 16px; border-radius: 8px; }
  .amount { font-size: {{ $layout === 'compact' ? 26 : 34 }}px; font-weight: 800; color: #0f172a; margin: 4px 0 2px 0; }
  .words { font-style: italic; color: #334155; font-size: {{ $base }}px; }
  .kv td { padding: {{ $layout === 'compact' ? 4 : 7 }}px 0; border-bottom: 1px solid #f1f5f9; font-size: {{ $base }}px; }
  .kv td.k { color: #64748b; width: 42%; }
  .kv td.v { font-weight: 600; }
  .chip { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 9.5px; font-weight: 700; background: #dcfce7; color: #166534; letter-spacing: .5px; }
  .summary td { padding: 6px 12px; font-size: {{ $base }}px; }
  .summary td.k { text-align: right; color: #64748b; }
  .summary td.v { text-align: right; width: 150px; font-weight: 600; }
  .verify { border: 1px solid #e2e8f0; border-radius: {{ $layout === 'classic' ? 0 : 10 }}px; padding: 14px 16px; }
  .code { font-family: "DejaVu Sans Mono", Menlo, monospace; font-weight: 700; font-size: 15px; letter-spacing: 2px; color: {{ $brand->strong() }}; }
  .highlight { color: {{ $brand->onAccent() }}; background: {{ $brand->accent() }}; padding: 1px 6px; border-radius: 4px; }
@if ($layout === 'classic')
  .letterhead { border: 1.5px solid {{ $brand->strong() }}; padding: 14px 16px; }
@endif
</style>
