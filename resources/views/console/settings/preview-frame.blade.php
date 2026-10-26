{{-- Live preview column (PLATFORM_CONSOLE.md § 9.1): the draft, reloaded after each save. Inline styles: the console's CSS is prebuilt. --}}
<div style="position:sticky;top:5rem;display:flex;flex-direction:column;gap:.5rem" data-test="preview-frame">
    <div style="display:flex;align-items:center;justify-content:space-between;font-size:.875rem">
        <span style="font-weight:600">{{ $label }}</span>
        <a href="{{ $url }}" target="_blank" rel="noopener" style="color:var(--primary-600);text-decoration:underline">Open full size</a>
    </div>
    <div style="overflow:hidden;border:1px solid rgba(127,127,127,.25);border-radius:.75rem;background:#fff">
        <iframe wire:key="preview-{{ $version }}" src="{{ $url }}?v={{ $version }}" title="{{ $label }}" loading="lazy" style="display:block;width:100%;height:42rem;border:0"></iframe>
    </div>
</div>
