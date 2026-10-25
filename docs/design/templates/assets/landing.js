// GoProperty landing page — interactions for the design reference.
// In the app these become small Alpine.js components; no framework is needed.
(() => {
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const naira = (n) => '₦' + Math.round(n).toLocaleString('en-NG');
  const check = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 5 5L20 7"/></svg>';

  /* ---------- Hero image preview (?hero=<url>&overlay=0.6) — mirrors theme.hero_image ---------- */
  const params = new URLSearchParams(location.search);
  const heroUrl = params.get('hero');
  if (heroUrl && /^(https?:|\.{0,2}\/|[\w-]+\/)/.test(heroUrl)) {
    const img = $('[data-hero-photo]');
    img.src = heroUrl; img.hidden = false;
    $('[data-hero]').classList.add('has-photo');
    const o = parseFloat(params.get('overlay'));
    if (!Number.isNaN(o)) document.documentElement.style.setProperty('--hero-overlay-opacity', Math.min(Math.max(o, 0), 1));
  }

  /* ---------- Nav: glass bar after scrolling ---------- */
  const nav = $('[data-nav]');
  const onScroll = () => nav.classList.toggle('is-scrolled', window.scrollY > 24);
  onScroll(); window.addEventListener('scroll', onScroll, { passive: true });

  /* ---------- Mobile menu ---------- */
  const menu = $('[data-menu]');
  const opener = $('[data-menu-open]');
  opener.addEventListener('click', () => menu.showModal());
  $('[data-menu-close]').addEventListener('click', () => menu.close());
  menu.addEventListener('click', (e) => { if (e.target === menu) menu.close(); });
  $$('a', menu).forEach((a) => a.addEventListener('click', () => menu.close()));
  menu.addEventListener('close', () => opener.focus());

  /* ---------- Generic tab lists (role=tablist, arrow keys) ---------- */
  function tabs(list, onSelect) {
    const items = $$('[role="tab"]', list);
    const select = (tab, focus) => {
      items.forEach((t) => { const on = t === tab; t.setAttribute('aria-selected', on); t.tabIndex = on ? 0 : -1; });
      if (focus) tab.focus();
      onSelect(tab);
    };
    items.forEach((t, i) => {
      t.addEventListener('click', () => select(t));
      t.addEventListener('keydown', (e) => {
        if (!['ArrowRight', 'ArrowLeft'].includes(e.key)) return;
        e.preventDefault();
        select(items[(i + (e.key === 'ArrowRight' ? 1 : -1) + items.length) % items.length], true);
      });
    });
    return select;
  }

  /* ---------- Workspace showcase ---------- */
  const urls = { agency: 'goproperty.ng/o/adeyemi-realty/today', owner: 'goproperty.ng/owner/statements/2026-10', tenant: 'goproperty.ng/portal' };
  tabs($('[data-tabs="view"]'), (tab) => {
    const view = tab.id.replace('tab-', '');
    $$('[role="tabpanel"][id^="view-"]').forEach((p) => { p.hidden = p.id !== 'view-' + view; });
    $('[data-url]').textContent = urls[view];
    $$('[data-phone]').forEach((p) => { p.hidden = !p.dataset.phone.split(' ').includes(view); });
  });

  /* ---------- Payment flows ---------- */
  const icon = {
    user: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>',
    bank: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10h18M5 10v9M19 10v9M9 10v9M15 10v9M3 21h18M12 3 3 8h18z"/></svg>',
    file: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H6v18h12V7z"/><path d="M14 3v4h4M9 13h6M9 17h4"/></svg>',
    link: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 14a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1M14 10a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1"/></svg>',
    eye: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>',
    ok: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 5 5L20 7"/></svg>',
  };
  const flows = {
    bank: {
      title: 'Matched from your bank statement',
      text: 'The tenant transfers to your account as usual. Upload the statement and GoProperty finds the bill each credit belongs to — by amount, name and narration.',
      checks: ['Works with every Nigerian bank’s statement export', 'Unclear credits are flagged, never guessed', 'You confirm — the receipt goes out'],
      steps: [
        ['user', 'Tenant transfers ₦1,800,000', 'To Adeyemi Realty · GTBank', ''],
        ['file', 'You upload the bank statement', 'CSV or Excel from internet banking', ''],
        ['link', 'GoProperty matches the credit', 'Flat 4B · rent · 98% sure', '<span class="chip chip--info">Matched</span>'],
        ['ok', 'You confirm · receipt sent', 'Email + tenant portal, with QR code', '<span class="chip chip--ok">Paid</span>'],
      ],
    },
    proof: {
      title: 'Tenant uploads a proof of payment',
      text: 'Paid at the bank or from another account? The tenant uploads the teller or transfer screenshot from their bill. It lands in your queue to check against your account.',
      checks: ['Photos are compressed on the phone before upload', 'Bank name and amount shown side by side', 'Reject with a reason — the tenant is told'],
      steps: [
        ['user', 'Tenant opens the bill', 'From the email, SMS or tenant portal', ''],
        ['file', 'Uploads proof of payment', 'Teller, screenshot or PDF', '<span class="chip chip--warn">Waiting</span>'],
        ['eye', 'Your team checks it', 'Against your bank account', ''],
        ['ok', 'Confirmed · receipt sent', '“Confirmed by T. Bello” recorded', '<span class="chip chip--ok">Paid</span>'],
      ],
    },
    paystack: {
      title: 'Card and transfer through your own Paystack',
      text: 'Connect your agency’s Paystack account and tenants can pay by card, bank transfer or USSD from the bill. The money settles to you; the payment confirms itself.',
      checks: ['Your Paystack keys, your settlement account', 'Confirmed automatically by Paystack webhook', 'Available on Growth and above'],
      steps: [
        ['user', 'Tenant taps “Pay online”', 'Branded pay page, no login needed', ''],
        ['bank', 'Pays with Paystack', 'Card, transfer or USSD', ''],
        ['link', 'Paystack confirms the payment', 'Amount checked against the bill', '<span class="chip chip--info">Verified</span>'],
        ['ok', 'Receipt sent instantly', 'No one has to lift a finger', '<span class="chip chip--ok">Paid</span>'],
      ],
    },
  };
  const stepsEl = $('[data-flow-steps]');
  let flowTimer;
  function showFlow(key) {
    const f = flows[key];
    $('[data-flow-title]').textContent = f.title;
    $('[data-flow-text]').textContent = f.text;
    $('[data-flow-checks]').innerHTML = f.checks.map((c) => `<li>${check}${c}</li>`).join('');
    stepsEl.innerHTML = f.steps.map(([ic, t, s, chip]) => `<li><span class="steps-v__icon">${icon[ic]}</span><div><strong>${t}</strong><span>${s}</span></div>${chip}</li>`).join('');
    const items = $$('li', stepsEl);
    clearInterval(flowTimer);
    const reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduced) { items.forEach((li) => li.classList.add('is-lit')); return; }
    let i = 0;
    const tick = () => {
      items.forEach((li, n) => { li.classList.toggle('is-lit', n <= i); li.classList.toggle('is-now', n === i); });
      i = i >= items.length - 1 ? 0 : i + 1;
    };
    tick(); flowTimer = setInterval(tick, 1400);
  }
  tabs($('[data-tabs="pay"]'), (tab) => showFlow(tab.dataset.method));
  showFlow('bank');

  /* ---------- Reminder timeline ---------- */
  const stages = {
    '-14': { stage: 'Bill issued', title: 'Your rent bill for Flat 4B is ready', text: 'Hi Chidinma, your rent of ₦1,800,000 for Nov 2026 – Oct 2027 is due on 01/11/2026. Pay to Adeyemi Realty, GTBank 0123456789, or tap the link to pay online.', btn: 'View and pay', ch: ['Email', 'SMS'], status: 'Unpaid · due in 14 days' },
    '-7': { stage: 'Reminder · 7 days', title: 'Rent due in 7 days', text: 'A friendly reminder that ₦1,800,000 rent for Flat 4B is due on 01/11/2026. Already paid? Upload your proof from the bill and we’ll confirm it.', btn: 'Pay or upload proof', ch: ['Email', 'SMS', 'WhatsApp'], status: 'Unpaid · due in 7 days' },
    '-1': { stage: 'Reminder · 1 day', title: 'Rent due tomorrow', text: 'Your rent of ₦1,800,000 for Flat 4B is due tomorrow, 01/11/2026. Pay now to keep your account up to date.', btn: 'Pay now', ch: ['Email', 'SMS', 'WhatsApp'], status: 'Unpaid · due tomorrow' },
    '1': { stage: 'Overdue notice', title: 'Your rent is now overdue', text: 'We haven’t received your rent of ₦1,800,000 for Flat 4B, due 01/11/2026. Please pay or contact Adeyemi Realty if you’ve already paid.', btn: 'Pay now', ch: ['Email', 'SMS'], status: 'Overdue · flagged on the agency’s Today list' },
  };
  const stageDays = Object.keys(stages).map(Number).sort((a, b) => a - b);
  const range = $('[data-days]');
  const min = +range.min, max = +range.max;
  $('[data-ticks]').innerHTML = [-14, -7, -1, 0, 1, 3].map((d) => `<span class="${stages[d] ? 'is-stage' : ''}" style="left:${((d - min) / (max - min)) * 100}%">${d > 0 ? '+' + d : d}</span>`).join('');
  function showStage() {
    const d = +range.value;
    $('[data-days-out]').textContent = d < 0 ? `${-d} day${d === -1 ? '' : 's'} before` : d === 0 ? 'Due today' : `${d} day${d === 1 ? '' : 's'} late`;
    const key = stageDays.filter((s) => s <= d).pop() ?? stageDays[0];
    const s = stages[key];
    $('[data-msg-stage]').textContent = s.stage;
    $('[data-msg-title]').textContent = s.title;
    $('[data-msg-text]').textContent = s.text;
    $('[data-msg-btn]').textContent = s.btn;
    $('[data-msg-channels]').innerHTML = ['Email', 'SMS', 'WhatsApp'].map((c) => `<span class="chip ${s.ch.includes(c) ? 'chip--info' : 'chip--muted'}">${c}</span>`).join('');
    $('[data-msg-status]').textContent = d === 0 ? 'Unpaid · due today' : s.status;
  }
  range.addEventListener('input', showStage); showStage();

  /* ---------- QR code (decorative, deterministic) ---------- */
  const qr = $('[data-qr]');
  if (qr) {
    let seed = 7; const rnd = () => ((seed = (seed * 9301 + 49297) % 233280) / 233280);
    const finder = (x, y) => `<rect x="${x}" y="${y}" width="7" height="7" fill="currentColor"/><rect x="${x + 1}" y="${y + 1}" width="5" height="5" fill="#fff"/><rect x="${x + 2}" y="${y + 2}" width="3" height="3" fill="currentColor"/>`;
    let cells = '';
    for (let y = 0; y < 25; y++) for (let x = 0; x < 25; x++) {
      const inFinder = (x < 8 && y < 8) || (x > 16 && y < 8) || (x < 8 && y > 16);
      if (!inFinder && rnd() > 0.52) cells += `M${x} ${y}h1v1h-1z`;
    }
    qr.innerHTML = `<rect width="25" height="25" fill="#fff"/><path d="${cells}" fill="currentColor"/>${finder(0, 0)}${finder(18, 0)}${finder(0, 18)}`;
    qr.style.color = '#0F172A';
  }

  /* ---------- Brand swatches + receipt flip ---------- */
  const doc = $('[data-flip]');
  $$('[data-swatches] .swatch').forEach((b) => b.addEventListener('click', () => {
    $$('[data-swatches] .swatch').forEach((x) => x.setAttribute('aria-pressed', x === b));
    $$('.doc', doc).forEach((d) => d.style.setProperty('--brand', b.dataset.brand));
    $$('[data-doc-name]').forEach((n) => { n.textContent = b.dataset.name; });
    $('[data-doc-init]').textContent = b.dataset.init;
  }));
  const flipBtn = $('[data-flip-btn]');
  flipBtn.addEventListener('click', () => {
    const on = doc.classList.toggle('is-flipped');
    flipBtn.setAttribute('aria-pressed', on);
    flipBtn.textContent = on ? 'Back to the receipt' : 'Scan the QR code';
  });

  /* ---------- Pricing calculator (BILLING_PAYMENTS.md § 1.1) ---------- */
  const plans = [
    { key: 'starter', name: 'Starter', base: 10000, incl: 10, unit: 700 },
    { key: 'growth', name: 'Growth', base: 35000, incl: 50, unit: 500 },
    { key: 'scale', name: 'Scale', base: 85000, incl: 200, unit: 350 },
  ];
  let period = 'month';
  const units = $('[data-units]');
  function calc() {
    const n = +units.value;
    $('[data-units-out]').textContent = n >= 1000 ? '1,000+' : n.toLocaleString('en-NG');
    let best = plans.map((p) => ({ ...p, price: p.base + Math.max(0, n - p.incl) * p.unit })).sort((a, b) => a.price - b.price)[0];
    if (best.price >= 250000 || n >= 1000) best = { key: 'enterprise', name: 'Enterprise', price: 250000, enterprise: true };
    const shown = period === 'year' ? best.price * 10 : best.price;
    $('[data-calc-price]').innerHTML = `${best.enterprise ? 'from ' : ''}${naira(shown)} <span>/ ${period}</span>`;
    $('[data-calc-meta]').textContent = best.enterprise
      ? 'Enterprise · custom contract · plus 7.5% VAT'
      : `${best.name} · ${naira(best.price / n)} per unit a month · plus 7.5% VAT`;
    $$('[data-plan]').forEach((el) => el.classList.toggle('is-match', el.dataset.plan === best.key));
  }
  units.addEventListener('input', calc);
  tabs($('[data-tabs="period"]'), (tab) => {
    period = tab.dataset.period;
    $$('[data-price-month]').forEach((el) => {
      const v = period === 'year' ? el.dataset.priceYear : el.dataset.priceMonth;
      el.innerHTML = v === 'Custom' ? 'Custom' : `${v} <small>/ ${period}</small>`;
    });
    calc();
  });
  calc();

  /* ---------- Reveal on scroll ---------- */
  const io = 'IntersectionObserver' in window && new IntersectionObserver((entries) => {
    entries.forEach((e) => { if (e.isIntersecting) { e.target.classList.add('is-in'); io.unobserve(e.target); } });
  }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });
  $$('.reveal').forEach((el) => (io ? io.observe(el) : el.classList.add('is-in')));
})();
