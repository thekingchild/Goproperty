// GoProperty auth pages — shared behaviour for the design reference (Livewire/Alpine in the app).
window.GP = (() => {
  const $ = (s, r = document) => r.querySelector(s);
  const params = new URLSearchParams(location.search);

  // Side image preview (?side=<url>&overlay=0.5) — mirrors theme.auth_image + overlay settings.
  const side = params.get('side');
  if (side && /^(https?:|\.{0,2}\/|[\w-]+\/)/.test(side)) {
    const img = $('[data-panel-photo]');
    img.src = side; img.hidden = false;
    $('[data-panel]').classList.add('has-photo');
    const o = parseFloat(params.get('overlay'));
    if (!Number.isNaN(o)) document.documentElement.style.setProperty('--auth-overlay-opacity', Math.min(Math.max(o, 0), 1));
  }
  // Keep preview params when moving between design states.
  document.querySelectorAll('[data-review] a').forEach((a) => {
    if (!side) return;
    const u = new URL(a.href, location.href);
    u.searchParams.set('side', side);
    if (params.get('overlay')) u.searchParams.set('overlay', params.get('overlay'));
    a.href = u.pathname.split('/').pop() + u.search;
  });

  // Show / hide password
  const eye = $('[data-eye]');
  if (eye) {
    const pw = document.getElementById(eye.getAttribute('aria-controls'));
    eye.addEventListener('click', () => {
      const show = pw.type === 'password';
      pw.type = show ? 'text' : 'password';
      eye.setAttribute('aria-pressed', show);
      eye.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
      $('[data-eye-open]', eye).hidden = show;
      $('[data-eye-closed]', eye).hidden = !show;
    });
    // Caps Lock warning
    const caps = $('[data-caps]');
    const capsCheck = (e) => { if (e.getModifierState) caps.hidden = !e.getModifierState('CapsLock'); };
    pw.addEventListener('keydown', capsCheck);
    pw.addEventListener('keyup', capsCheck);
    pw.addEventListener('blur', () => { caps.hidden = true; });
  }

  return {
    busy(btn, on) {
      if (on) btn.setAttribute('aria-busy', 'true'); else btn.removeAttribute('aria-busy');
      const sp = $('.spinner', btn); if (sp) sp.hidden = !on;
    },
    markState(state) {
      document.querySelectorAll('[data-review] [data-state]').forEach((a) => a.setAttribute('aria-current', a.dataset.state === state));
    },
  };
})();
