{{--
    Idle warning (UX_DESIGN.md § 3.2): two minutes before the 30-minute
    staff timeout, a dialog offers "Stay signed in". Any Livewire request
    counts as activity; at the limit the page goes to the sign-in screen.
--}}
@php
    $timeout = \App\Http\Middleware\StaffIdleTimeout::MINUTES * 60;
    $warn = $timeout - \App\Http\Middleware\StaffIdleTimeout::WARN_SECONDS_BEFORE;
@endphp
<div id="gp-idle" role="alertdialog" aria-modal="true" aria-labelledby="gp-idle-title" hidden
     style="position:fixed;inset:0;z-index:100;display:grid;place-items:center;background:rgba(2,6,23,.6);padding:1rem">
  <div style="width:min(100%,24rem);border-radius:1rem;background:#fff;color:#0f172a;padding:1.5rem;box-shadow:0 30px 80px -30px rgba(0,0,0,.7);font-family:inherit">
    <p id="gp-idle-title" style="margin:0 0 .5rem;font-weight:700;font-size:1.125rem">Still there?</p>
    <p style="margin:0 0 1.25rem;color:#475569">For security you'll be signed out in <b data-idle-left>2:00</b> unless you continue.</p>
    <button type="button" data-idle-stay style="width:100%;min-height:44px;border:0;border-radius:.75rem;background:var(--primary-600);color:#fff;font-weight:600;cursor:pointer">Stay signed in</button>
  </div>
</div>
<script>
  (() => {
    const dialog = document.getElementById('gp-idle');
    const left = dialog.querySelector('[data-idle-left]');
    const warnAt = {{ $warn }} * 1000;
    const endAt = {{ $timeout }} * 1000;
    let since = Date.now();
    const reset = () => { since = Date.now(); dialog.hidden = true; };
    document.addEventListener('livewire:init', () => window.Livewire.hook('request', () => reset()));
    dialog.querySelector('[data-idle-stay]').addEventListener('click', () => {
      fetch(@json(route('filament.console.session-ping')), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } }).finally(reset);
    });
    setInterval(() => {
      const idle = Date.now() - since;
      if (idle >= endAt) { window.location.assign(@json(\Filament\Facades\Filament::getLoginUrl())); return; }
      if (idle >= warnAt) {
        const s = Math.max(0, Math.ceil((endAt - idle) / 1000));
        left.textContent = Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
        if (dialog.hidden) { dialog.hidden = false; dialog.querySelector('[data-idle-stay]').focus(); }
      }
    }, 1000);
  })();
</script>
