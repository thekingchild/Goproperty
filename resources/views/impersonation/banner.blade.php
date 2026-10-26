{{--
    The support banner (PLATFORM_CONSOLE.md § 6), put at the top of every
    customer page during a support session by App\Http\Middleware\ResumeImpersonation.
    Inline styles: it must show on any layout, built assets or not. Fixed to
    the top (the app layout's body is a CSS grid, so an extra child would be
    squeezed into a grid cell); the script pads the body by its height.
--}}
@php
    /** @var \App\Models\ImpersonationSession $session */
    $product = app(\App\Theme\ThemeContext::class)->current()->name();
    $staffName = \App\Models\ImpersonationSession::shortName($session->staff->name);
    $readOnly = $session->mode === \App\Platform\Impersonation\ImpersonationMode::ReadOnly;
@endphp
<div id="gp-support-banner" role="region" aria-label="{{ __('Support session') }}" data-test="impersonation-banner"
    style="position:fixed;top:0;left:0;right:0;box-sizing:border-box;z-index:2147483000;display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:.5rem 1rem;padding:.5rem 1rem;font:500 14px/1.4 system-ui,sans-serif;background:var(--color-brand-accent-50,#fffbeb);color:var(--ink,#111827);border-bottom:2px solid var(--color-brand-accent,#f59e0b);">
    <span>
        <strong>{{ $product }} support ({{ $staffName }})</strong> is viewing this account
        <span style="opacity:.8;">· {{ $readOnly ? __('Read-only') : __('Write mode') }} · {{ __('ends at :time', ['time' => $session->expires_at->format('H:i')]) }}</span>
    </span>
    <form method="POST" action="{{ route('impersonation.end') }}" style="margin:0;">
        @csrf
        <button type="submit" data-test="end-impersonation"
            style="cursor:pointer;padding:.25rem .75rem;border-radius:.375rem;border:1px solid currentColor;background:transparent;color:inherit;font:inherit;">
            {{ __('End session') }}
        </button>
    </form>
</div>
<script>
    (function () {
        var banner = document.getElementById('gp-support-banner');
        var pad = function () { document.body.style.paddingTop = banner.offsetHeight + 'px'; };
        pad();
        window.addEventListener('resize', pad);
    })();
</script>
