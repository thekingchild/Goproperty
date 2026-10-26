{{--
    Landing page (UX_DESIGN.md § 4), converted from the approved design
    docs/design/templates/landing.html. Static sections are kept as designed;
    the brand name, hero, prices, FAQ and footer come from the theme
    settings and the plan catalogue (PLATFORM_CONSOLE.md § 9).
--}}
<x-layouts::marketing :schema="true" :script="true">
<div class="nav-bar" data-nav>
  @include('marketing.partials.announcement')
  <div class="wrap nav">
    @include('marketing.partials.brand', ['tone' => 'dark'])
    <nav class="nav__links" aria-label="Primary">
      <a href="#product">Product</a>
      <a href="#features">Features</a>
      <a href="#payments">Payments</a>
      <a href="#pricing">Pricing</a>
      <a href="#faq">FAQ</a>
    </nav>
    <div class="nav__actions">
      <a class="nav__login" href="{{ route('login') }}">Log in</a>
      <a class="pill pill--login" href="{{ route('login') }}">Log in</a>
      <a class="pill pill--trial" href="{{ $trialUrl }}">Start free trial</a>
      <button class="menu-btn" type="button" aria-label="Open menu" aria-haspopup="dialog" data-menu-open>
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
      </button>
    </div>
  </div>
  <dialog class="menu" aria-labelledby="menu-title" data-menu>
    <div class="menu__head">
      <p id="menu-title">{{ $brand }}</p>
      <button class="menu__close" type="button" aria-label="Close menu" data-menu-close><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></button>
    </div>
    <nav aria-label="Menu">
      <a href="#product">Product</a>
      <a href="#features">Features</a>
      <a href="#payments">Payments</a>
      <a href="#pricing">Pricing</a>
      <a href="#faq">FAQ</a>
    </nav>
    <div class="menu__ctas">
      <a class="btn btn--primary" href="{{ $trialUrl }}">Start free trial</a>
      <a class="btn btn--ghost" href="{{ route('login') }}">Log in</a>
    </div>
  </dialog>
</div>

@include('marketing.partials.hero')

<main id="main" tabindex="-1">

  <!-- ============ FACTS ============ -->
  <section class="facts" aria-label="{{ $brand }} at a glance">
    <ul class="wrap facts__grid">
      <li><span class="fact__value">₦0</span><span class="fact__label">commission on rent — tenants pay your account directly</span></li>
      <li><span class="fact__value">{{ $plans->starter()->base->format() }}</span><span class="fact__label">a month to start, for up to {{ $plans->starter()->includedUnits }} units</span></li>
      <li><span class="fact__value">Dry run</span><span class="fact__label">before any Excel import — see every row before it's saved</span></li>
    </ul>
  </section>

  <!-- ============ WORKSPACE SHOWCASE ============ -->
  <section class="section section--canvas" id="product" aria-labelledby="product-title">
    <div class="wrap">
      <div class="reveal">
        <p class="eyebrow">The workspace</p>
        <h2 class="h2" id="product-title">Every building, every tenant. <b>One desk.</b></h2>
        <p class="lede">Your team starts the day on a "Today" list — payments to confirm, bills going overdue, leases ending, tickets waiting. Landlords and tenants each get their own simple view of the same records.</p>
      </div>

      <div class="showcase__tabs reveal">
        <div class="seg" role="tablist" aria-label="Choose a view" data-tabs="view">
          <button type="button" role="tab" aria-selected="true" aria-controls="view-agency" id="tab-agency">Agency desk</button>
          <button type="button" role="tab" aria-selected="false" aria-controls="view-owner" id="tab-owner" tabindex="-1">Landlord view</button>
          <button type="button" role="tab" aria-selected="false" aria-controls="view-tenant" id="tab-tenant" tabindex="-1">Tenant app</button>
        </div>
      </div>

      <div class="stage reveal">
        <div class="window">
          <div class="window__bar">
            <span class="window__dots" aria-hidden="true"><i></i><i></i><i></i></span>
            <span class="window__url" data-url>goproperty.ng/o/adeyemi-realty/today</span>
            <span class="chip chip--ok window__live">● Live</span>
          </div>
          <div class="app">
            <div class="app__side" aria-hidden="true">
              <div class="app__org"><span class="app__org-logo">AR</span>Adeyemi Realty</div>
              <div class="app__nav">
                <span class="is-on">Today <b>9</b></span>
                <span>Properties</span><span>Tenants</span><span>Leases</span>
              </div>
              <p class="app__group">Money</p>
              <div class="app__nav"><span>Bills</span><span>Payments <b>3</b></span><span>Bank statements</span></div>
              <p class="app__group">People</p>
              <div class="app__nav"><span>Tickets <b>4</b></span><span>Building Groups</span><span>Owners</span></div>
            </div>

            <!-- Agency -->
            <div class="app__main" id="view-agency" role="tabpanel" aria-labelledby="tab-agency">
              <p class="app__date">Monday, 26 October</p>
              <p class="app__title">Work that needs you today</p>
              <div class="kpis">
                <div class="kpi kpi--accent"><span>Collected in Oct</span><strong class="num">₦18,450,000</strong></div>
                <div class="kpi"><span>Outstanding</span><strong class="num">₦2,100,000</strong></div>
                <div class="kpi"><span>Occupancy</span><strong class="num">94%</strong></div>
              </div>
              <ul class="todo">
                <li><div class="todo__text"><strong>5 bank statement lines matched</strong><span>GTBank statement · 24/10/2026 · ready to confirm</span></div><div class="todo__act"><span class="chip chip--info">Matched</span><span class="mini-btn">Confirm</span></div></li>
                <li><div class="todo__text"><strong>3 payment proofs uploaded</strong><span>Flat 4B Lekki Court · Block C, Unit 2 · +1 more</span></div><div class="todo__act"><span class="chip chip--warn">Waiting</span><span class="mini-btn">Review</span></div></li>
                <li><div class="todo__text"><strong>2 rent bills overdue</strong><span>Reminders sent yesterday by email and SMS</span></div><div class="todo__act"><span class="chip chip--bad">Overdue</span><span class="mini-btn mini-btn--ghost">Open</span></div></li>
                <li><div class="todo__text"><strong>1 lease ends in 30 days</strong><span>Mrs. Okafor · Flat 2A Adeola Heights</span></div><div class="todo__act"><span class="chip chip--muted">Renewal</span><span class="mini-btn mini-btn--ghost">Renew</span></div></li>
              </ul>
            </div>

            <!-- Owner -->
            <div class="app__main" id="view-owner" role="tabpanel" aria-labelledby="tab-owner" hidden>
              <p class="app__date">Owner portal · October statement</p>
              <p class="app__title">Adeola Heights, Ikeja</p>
              <div class="bars" aria-label="Rent collected by month, May to October">
                <div><i style="height:52%"></i>May</div><div><i style="height:64%"></i>Jun</div><div><i style="height:58%"></i>Jul</div>
                <div><i style="height:76%"></i>Aug</div><div><i style="height:70%"></i>Sep</div><div><i style="height:92%"></i>Oct</div>
              </div>
              <table class="ledger">
                <tr><td>Rent collected (12 units)</td><td>₦9,600,000</td></tr>
                <tr><td>Service charge collected</td><td>₦1,140,000</td></tr>
                <tr><td>Repairs · borehole pump</td><td>− ₦185,000</td></tr>
                <tr><td>Management fee (7.5%)</td><td>− ₦720,000</td></tr>
                <tr><td><b>Net to you</b></td><td>₦9,835,000</td></tr>
              </table>
            </div>

            <!-- Tenant -->
            <div class="app__main" id="view-tenant" role="tabpanel" aria-labelledby="tab-tenant" hidden>
              <p class="app__date">Tenant portal · goproperty.ng/portal</p>
              <p class="app__title">Good morning, Chidinma</p>
              <ul class="todo">
                <li><div class="todo__text"><strong>Rent · Nov 2026 – Oct 2027</strong><span>Due 01/11/2026 · Flat 4B Lekki Court</span></div><div class="todo__act"><span class="num"><b>₦1,800,000</b></span><span class="mini-btn">Pay</span></div></li>
                <li><div class="todo__text"><strong>Service charge · Q4</strong><span>Paid 12/10/2026 · receipt ready</span></div><div class="todo__act"><span class="chip chip--ok">Paid</span><span class="mini-btn mini-btn--ghost">Receipt</span></div></li>
                <li><div class="todo__text"><strong>Kitchen sink leaking</strong><span>Ticket #T-0192 · plumber visiting Thursday</span></div><div class="todo__act"><span class="chip chip--info">In progress</span></div></li>
              </ul>
            </div>
          </div>
        </div>

        <!-- Phone overlay (changes with the tab) -->
        <div class="phone" aria-hidden="true">
          <div class="phone__screen">
            <div class="phone__status"><span>9:41</span><span>●●● 4G</span></div>
            <div class="phone__brand"><i>AR</i>Adeyemi Realty</div>
            <div data-phone="agency owner">
              <div class="rcpt">
                <div class="rcpt__tick"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 5 5L20 7"/></svg></div>
                <p class="rcpt__label">Payment confirmed</p>
                <p class="rcpt__amt">₦1,800,000</p>
                <span class="chip chip--ok">Verified receipt</span>
                <dl>
                  <div><dt>Receipt</dt><dd>RCT-ADR-000318</dd></div>
                  <div><dt>For</dt><dd>Rent · Flat 4B</dd></div>
                  <div><dt>Confirmed by</dt><dd>T. Bello</dd></div>
                  <div><dt>Date</dt><dd>26/10/2026</dd></div>
                </dl>
              </div>
              <span class="phone__cta">Download receipt</span>
            </div>
            <div data-phone="tenant" hidden>
              <div class="tenant-actions"><span><i>₦</i>Pay a bill</span><span><i>↑</i>Upload proof</span><span><i>!</i>Report a problem</span></div>
              <div class="tenant-bills">
                <div class="tenant-bill"><strong>Rent <span class="num">₦1,800,000</span></strong><span>Due in 6 days</span></div>
                <div class="tenant-bill"><strong>Water · Oct <span class="num">₦6,500</span></strong><span>Due 05/11/2026</span></div>
                <div class="tenant-bill"><strong>Building Group</strong><span>3 new posts from your neighbours</span></div>
              </div>
            </div>
          </div>
        </div>
      </div>
      <p class="showcase__caption"><span>Sample data.</span><span>Each agency's portal shows its own logo and colours.</span></p>
    </div>
  </section>

  <!-- ============ STATEMENT BAND ============ -->
  <section class="section section--dark" aria-labelledby="band-title">
    <div class="wrap">
      <h2 class="band__title reveal" id="band-title">Rent records don't belong in <s>WhatsApp chats</s> and <s>Excel sheets</s>. <b>Put them somewhere that adds up.</b></h2>
      <ul class="band__grid reveal">
        <li><h3>Matched, not chased</h3><p>Upload your bank statement; payments are matched to the right bills for you to confirm.</p></li>
        <li><h3>Receipts in seconds</h3><p>The moment you confirm, the tenant gets a branded receipt with a QR code anyone can verify.</p></li>
        <li><h3>Landlords stay informed</h3><p>Owners see income, expenses and payouts for their buildings without calling you.</p></li>
        <li><h3>Your money, your account</h3><p>Tenants pay you directly. {{ $brand }} never holds or splits rent.</p></li>
      </ul>
    </div>
  </section>

  <!-- ============ BENTO FEATURES ============ -->
  <section class="section" id="features" aria-labelledby="features-title">
    <div class="wrap">
      <div class="reveal">
        <p class="eyebrow">What's inside</p>
        <h2 class="h2" id="features-title">Built for how Nigerian agencies <b>actually work.</b></h2>
        <p class="lede">Yearly rent, service charges, caution deposits, borehole repairs and bank transfers with half-filled narrations. {{ $brand }} was designed around them.</p>
      </div>

      <div class="bento">
        <article class="tile tile--w4 reveal">
          <div><h3>Bank statement matching</h3><p>Upload your statement from any Nigerian bank. Each credit is matched to the right tenant and bill; you only confirm.</p></div>
          <div class="tile__visual">
            <table class="match">
              <thead><tr><th>Date</th><th class="hide-sm">Narration</th><th class="r">Amount</th><th>Match</th></tr></thead>
              <tbody>
                <tr><td>24/10</td><td class="desc hide-sm">TRF/OKAFOR C/RENT FLAT 2A</td><td class="r num">₦2,400,000</td><td><span class="chip chip--ok">Flat 2A · rent</span></td></tr>
                <tr><td>24/10</td><td class="desc hide-sm">NIP/ADEBAYO T/SVC CHG</td><td class="r num">₦95,000</td><td><span class="chip chip--ok">Unit C2 · service</span></td></tr>
                <tr><td>23/10</td><td class="desc hide-sm">TRANSFER FROM IBRAHIM M</td><td class="r num">₦1,200,000</td><td><span class="chip chip--warn">2 possible</span></td></tr>
              </tbody>
            </table>
          </div>
        </article>

        <article class="tile tile--w2 tile--dark reveal">
          <div><h3>Building Group</h3><p>A private board for each building — notices, replies and photos, without the 2 a.m. WhatsApp noise.</p></div>
          <div class="tile__visual chat" aria-hidden="true">
            <p><small>Estate manager</small>Water will be off 10am–12pm Saturday for tank cleaning.</p>
            <span class="react">👍 14</span>
            <p class="me">Thanks for the heads-up!</p>
          </div>
        </article>

        <article class="tile tile--w2 reveal">
          <div><h3>Leases on any cycle</h3><p>From short lets to two-year leases. Renewal reminders go out 60, 30 and 7 days before the end.</p></div>
          <div class="tile__visual cycles" aria-hidden="true">
            <span>Daily</span><span>Weekly</span><span>Monthly</span><span>Quarterly</span><span>6-monthly</span><span class="is-on">Yearly</span><span>2-yearly</span>
          </div>
        </article>

        <article class="tile tile--w2 reveal">
          <div><h3>Maintenance tickets</h3><p>Tenants report a problem with photos; you reply, assign and close — with a full history.</p></div>
          <div class="tile__visual pipeline" aria-hidden="true">
            <div class="done"><i></i>Submitted · Kitchen sink leaking</div>
            <div class="done"><i></i>Under review</div>
            <div class="now"><i></i>In progress · plumber Thursday</div>
            <div><i></i>Resolved</div>
          </div>
        </article>

        <article class="tile tile--w2 tile--accent reveal">
          <div><h3>Utilities and service charge</h3><p>Bill water, power, waste and service charge alongside rent — on the same receipt trail.</p></div>
          <div class="tile__visual utils" aria-hidden="true">
            <span>Water<b class="num">₦6,500</b></span><span>Power<b class="num">₦21,000</b></span><span>Service<b class="num">₦95,000</b></span>
          </div>
        </article>

        <article class="tile tile--w3 reveal">
          <div><h3>Bring your existing book</h3><p>Import owners, units, tenants, running leases and opening balances from Excel. A dry run shows every row first; nothing is back-billed.</p></div>
          <div class="tile__visual import" aria-hidden="true">
            <div class="import__bar"><i style="width:91%;background:var(--success)"></i><i style="width:6%;background:var(--color-brand-accent)"></i><i style="width:3%;background:var(--danger)"></i></div>
            <div class="import__row"><span><b>214</b> rows ready</span><span><b>14</b> need a look</span><span><b>6</b> errors</span></div>
          </div>
        </article>

        <article class="tile tile--w3 reveal">
          <div><h3>Owner statements</h3><p>Monthly statements for every landlord — collected, spent, your fee and net payout — as a page and a PDF.</p></div>
          <div class="tile__visual" aria-hidden="true">
            <svg class="spark" viewBox="0 0 300 72" preserveAspectRatio="none"><defs><linearGradient id="sg" x1="0" y1="0" x2="0" y2="1"><stop offset="0" style="stop-color:var(--color-primary-500)" stop-opacity=".25"/><stop offset="1" style="stop-color:var(--color-primary-500)" stop-opacity="0"/></linearGradient></defs><path d="M0 58 L40 50 L80 54 L120 38 L160 42 L200 26 L240 30 L300 10 L300 72 L0 72Z" fill="url(#sg)"/><path d="M0 58 L40 50 L80 54 L120 38 L160 42 L200 26 L240 30 L300 10" fill="none" style="stroke:var(--color-primary-700)" stroke-width="2.5" stroke-linejoin="round"/><circle cx="300" cy="10" r="4" style="fill:var(--color-brand-accent)"/></svg>
          </div>
        </article>
      </div>
    </div>
  </section>

  <!-- ============ PAYMENTS ============ -->
  <section class="section section--canvas" id="payments" aria-labelledby="pay-title">
    <div class="wrap">
      <div class="head-row reveal">
        <div>
          <p class="eyebrow">Payments</p>
          <h2 class="h2" id="pay-title">Tenants pay you. <b>We keep the record straight.</b></h2>
        </div>
        <div class="seg" role="tablist" aria-label="Payment method" data-tabs="pay">
          <button type="button" role="tab" aria-selected="true" data-method="bank">Bank transfer</button>
          <button type="button" role="tab" aria-selected="false" data-method="proof" tabindex="-1">Proof upload</button>
          <button type="button" role="tab" aria-selected="false" data-method="paystack" tabindex="-1">Your Paystack</button>
        </div>
      </div>

      <div class="flow reveal">
        <div class="flow__copy" aria-live="polite">
          <h3 data-flow-title>Matched from your bank statement</h3>
          <p data-flow-text>The tenant transfers to your account as usual. Upload the statement and {{ $brand }} finds the bill each credit belongs to — by amount, name and narration.</p>
          <ul class="checks" data-flow-checks>
            <li><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 5 5L20 7"/></svg>Works with every Nigerian bank's statement export</li>
            <li><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 5 5L20 7"/></svg>Unclear credits are flagged, never guessed</li>
            <li><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 5 5L20 7"/></svg>You confirm — the receipt goes out</li>
          </ul>
        </div>
        <ol class="steps-v" data-flow-steps aria-label="Payment steps"></ol>
      </div>

      <ul class="flow__notes reveal">
        <li><strong>No commission on rent</strong><span>Your subscription is the only thing you pay {{ $brand }}.</span></li>
        <li><strong>"Confirmed by" on every payment</strong><span>Each confirmation records who approved it, and when.</span></li>
        <li><strong>Transaction Tiers</strong><span>Verified limits for how much each agency manages, as Nigerian rules expect.</span></li>
      </ul>
    </div>
  </section>

  <!-- ============ REMINDERS ============ -->
  <section class="section" aria-labelledby="remind-title">
    <div class="wrap">
      <div class="reveal">
        <p class="eyebrow">Reminders</p>
        <h2 class="h2" id="remind-title">Rent reminders that go out <b>on time, every time.</b></h2>
        <p class="lede">Bills and reminders are sent on a schedule you set, by email, SMS or your own WhatsApp Business number. Drag to see what the tenant receives, and when.</p>
      </div>
      <div class="remind reveal">
        <div class="scrub">
          <div class="scrub__head"><span id="days-label">Days to rent due date</span><strong data-days-out>14 days before</strong></div>
          <input class="range" type="range" min="-14" max="3" step="1" value="-14" aria-labelledby="days-label" data-days>
          <div class="ticks" aria-hidden="true" data-ticks></div>
          <p class="scrub__note">Example schedule: bill issued 14 days before, reminders 7 days and 1 day before, overdue notice the day after. Every step is editable per agency.</p>
        </div>
        <div class="msg" aria-live="polite">
          <div class="msg__top"><span class="msg__from"><i>AR</i>Adeyemi Realty</span><span data-msg-stage>Bill issued</span></div>
          <div class="msg__body">
            <h4 data-msg-title>Your rent bill for Flat 4B is ready</h4>
            <p data-msg-text>Hi Chidinma, your rent of ₦1,800,000 for Nov 2026 – Oct 2027 is due on 01/11/2026. Pay to Adeyemi Realty, GTBank 0123456789, or tap the link to pay online.</p>
            <span class="mini-btn" data-msg-btn>View and pay</span>
            <div class="msg__channels" data-msg-channels><span class="chip chip--info">Email</span><span class="chip chip--info">SMS</span><span class="chip chip--muted">WhatsApp</span></div>
            <p class="msg__status">Bill status: <b data-msg-status>Unpaid · due in 14 days</b></p>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- ============ BRANDED DOCUMENTS ============ -->
  <section class="section section--canvas" aria-labelledby="docs-title">
    <div class="wrap docs">
      <div class="reveal">
        <p class="eyebrow">Your brand</p>
        <h2 class="h2" id="docs-title">Receipts with your name on them. <b>And proof they're real.</b></h2>
        <p class="lede">Invoices, receipts and statements carry your logo, colours and letterhead. Every one has a QR code that opens a verification page — so a fake or edited receipt is caught in seconds.</p>
        <div class="swatches" role="group" aria-label="Try an agency colour" data-swatches>
          <button class="swatch" type="button" aria-pressed="true" style="--sw:#0E6B5C" data-brand="#0E6B5C" data-name="Adeyemi Realty" data-init="AR"><i></i>Teal</button>
          <button class="swatch" type="button" aria-pressed="false" style="--sw:#7C2D12" data-brand="#7C2D12" data-name="Okoro &amp; Sons Estates" data-init="OS"><i></i>Brick</button>
          <button class="swatch" type="button" aria-pressed="false" style="--sw:#4338CA" data-brand="#4338CA" data-name="Lekki Homes Ltd" data-init="LH"><i></i>Indigo</button>
          <button class="swatch" type="button" aria-pressed="false" style="--sw:#111827" data-brand="#111827" data-name="Bello Property Co." data-init="BP"><i></i>Charcoal</button>
        </div>
        <div class="flip-ctrl">
          <button class="btn btn--ghost" type="button" data-flip-btn aria-pressed="false">Scan the QR code</button>
          <span>Flip to the page a tenant or landlord sees.</span>
        </div>
      </div>

      <div class="flip reveal" data-flip>
        <div class="flip__inner">
          <div class="flip__face doc" data-doc>
            <div class="doc__bar"></div>
            <div class="doc__body">
              <div class="doc__head">
                <div class="doc__org"><span class="doc__logo" data-doc-init>AR</span><div><strong data-doc-name>Adeyemi Realty</strong><span>RC 1234567 · Ikeja, Lagos</span></div></div>
                <p class="doc__type">RECEIPT<small>RCT-ADR-2026-000318</small></p>
              </div>
              <div class="doc__amount"><span>Amount received</span><strong class="num">₦1,800,000</strong></div>
              <dl class="doc__rows">
                <div><dt>Received from</dt><dd>Chidinma Eze</dd></div>
                <div><dt>For</dt><dd>Rent · Flat 4B, Lekki Court · Nov 2026 – Oct 2027</dd></div>
                <div><dt>Method</dt><dd>Bank transfer · GTBank</dd></div>
                <div><dt>Confirmed by</dt><dd>Tunde Bello · 26/10/2026 10:42</dd></div>
              </dl>
              <div class="doc__foot">
                <div class="doc__verify"><svg class="qr" viewBox="0 0 25 25" data-qr shape-rendering="crispEdges" aria-label="QR code"></svg><span>Verify at goproperty.ng/verify<b>K7M2-QX9P</b></span></div>
                <span class="stamp">PAID</span>
              </div>
            </div>
          </div>
          <div class="flip__face flip__back doc">
            <div class="doc__bar"></div>
            <div class="verify-page">
              <span class="verify-page__url">goproperty.ng/verify/K7M2-QX9P</span>
              <div class="verify-ok"><i><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 5 5L20 7"/></svg></i><div><strong>Genuine receipt</strong>Issued by <span data-doc-name>Adeyemi Realty</span> through {{ $brand }}</div></div>
              <dl class="doc__rows">
                <div><dt>Receipt</dt><dd>RCT-ADR-2026-000318</dd></div>
                <div><dt>Amount</dt><dd>₦1,800,000</dd></div>
                <div><dt>Date</dt><dd>26/10/2026</dd></div>
                <div><dt>Status</dt><dd><span class="chip chip--ok">Valid</span></dd></div>
              </dl>
              <p style="margin:0;font-size:.8125rem;color:var(--muted)">If this receipt is ever voided, this page will say so.</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- ============ PRICING ============ -->
  <section class="section" id="pricing" aria-labelledby="pricing-title">
    <div class="wrap">
      <div class="reveal">
        <p class="eyebrow">Pricing</p>
        <h2 class="h2" id="pricing-title">Priced per unit. <b>Less than 1% of the rent.</b></h2>
        <p class="lede">A base price that includes some units, then a small fee for each extra one. No fee on payments. Every plan starts with a {{ $plans->trialDays() }}-day free trial — no card.</p>
      </div>

      <div class="calc reveal">
        <div>
          <label for="units">How many units do you manage?</label>
          <div class="calc__units"><output for="units" data-units-out>{{ number_format($calcUnits) }}</output><span>blocks / apartments</span></div>
          <input class="range" id="units" type="range" min="1" max="{{ $calcMax }}" value="{{ $calcUnits }}" data-units>
        </div>
        <div class="calc__result" aria-live="polite">
          <small>Best plan for you</small>
          <p class="calc__price" data-calc-price>{{ $calcPlan->priceFor($calcUnits)->format() }} <span>/ month</span></p>
          <p class="calc__meta" data-calc-meta>{{ $calcPlan->name }} · {{ $calcPerUnit->format() }} per unit a month · plus {{ $plans->vatPercent() }}% VAT</p>
        </div>
      </div>

      <div class="billing-toggle reveal">
        <div class="seg" role="tablist" aria-label="Billing period" data-tabs="period">
          <button type="button" role="tab" aria-selected="true" data-period="month">Monthly</button>
          <button type="button" role="tab" aria-selected="false" data-period="year" tabindex="-1">Yearly <span class="save-tag">{{ 12 - $plans->monthsPaidPerYear() }} months free</span></button>
        </div>
      </div>

      @include('marketing.partials.plans')
      <p class="price-note">Prices exclude {{ $plans->vatPercent() }}% VAT. Units are blocks/apartments, counted on your billing day.</p>
    </div>
  </section>

  <!-- ============ SECURITY ============ -->
  <section class="section section--dark" aria-labelledby="sec-title">
    <div class="wrap">
      <div class="reveal">
        <p class="eyebrow">Security</p>
        <h2 class="h2" id="sec-title">Serious about money. <em>Serious about security.</em></h2>
        <p class="lede">You're trusting us with tenants' details and your firm's records. Here's how we look after them.</p>
      </div>
      <div class="sec-grid reveal">
        <div><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 21v-1a6 6 0 0 1 9.5-4.9"/><circle cx="9" cy="7" r="4"/><circle cx="17.5" cy="14.5" r="2.5"/><path d="M17.5 17v5M17.5 19.5H20"/></svg><h3>Passkeys and two-factor sign-in</h3><p>Sign in with Face ID or a fingerprint. Owners of larger agencies must turn on 2FA.</p></div>
        <div><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6z"/><path d="m9 12 2 2 4-4"/></svg><h3>Bot and break-in protection</h3><p>Rate limits, lockouts and a Cloudflare challenge stop password-guessing attacks.</p></div>
        <div><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h10"/></svg><h3>Every action logged</h3><p>Who confirmed a payment, who changed a lease, who signed in and from where.</p></div>
        <div><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/></svg><h3>Encrypted nightly backups</h3><p>Database and files backed up separately, off-site, and test-restored every month.</p></div>
        <div><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg><h3>NDPA compliant</h3><p>Built to the Nigeria Data Protection Act — tenants' data is used only to run their tenancy.</p></div>
        <div><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3M21 14v7h-7"/></svg><h3>Verifiable documents</h3><p>Every invoice and receipt can be checked by QR code, so forgeries go nowhere.</p></div>
      </div>
    </div>
  </section>

  <!-- ============ GETTING STARTED ============ -->
  <section class="section section--canvas" id="start" aria-labelledby="start-title">
    <div class="wrap">
      <div class="reveal">
        <p class="eyebrow">Getting started</p>
        <h2 class="h2" id="start-title">Live this week, <b>not next quarter.</b></h2>
      </div>
      <ol class="journey reveal">
        <li><h3>Start your trial</h3><p>Create your agency in two minutes. No card, no sales call.</p><small>Day 1</small></li>
        <li><h3>Import your book</h3><p>Upload owners, units, tenants and running leases from Excel — dry run first.</p><small>Day 1–2</small></li>
        <li><h3>Add your brand</h3><p>Logo, colours, letterhead and bank accounts on every bill and receipt.</p><small>Day 2</small></li>
        <li><h3>Send your first bills</h3><p>Tenants get bills and reminders; confirmed payments get receipts.</p><small>Day 3</small></li>
      </ol>
    </div>
  </section>

  <!-- ============ FAQ ============ -->
  <section class="section" id="faq" aria-labelledby="faq-title">
    <div class="wrap faq">
      <div class="reveal">
        <p class="eyebrow">FAQ</p>
        <h2 class="h2" id="faq-title">Questions agencies <b>ask us first.</b></h2>
        <p class="lede">Something else? <a href="{{ $demoUrl }}">Talk to our team</a>.</p>
      </div>
      <div class="faq__list reveal">
        @foreach ($theme->get('site.faq') ?? [] as $item)
          <details @if ($loop->first) open @endif><summary>{{ $item['question'] }}<i aria-hidden="true"></i></summary>{!! $item['answer'] !!}</details>
        @endforeach
      </div>
    </div>
  </section>

  <!-- ============ FINAL CTA ============ -->
  <section class="cta" aria-labelledby="cta-title">
    <div class="cta__art" aria-hidden="true"><img src="{{ asset('images/skyline.svg') }}" alt=""></div>
    <div class="wrap reveal">
      <h2 id="cta-title">Your next rent cycle could be <span>the easy one.</span></h2>
      <p>Start a {{ $plans->trialDays() }}-day free trial — no card required. Or let us walk you through it with your own data.</p>
      <div class="cta__btns">
        <a class="btn btn--white" href="{{ $trialUrl }}">Start free trial</a>
        <a class="btn btn--outline-light" href="{{ $demoUrl }}">Book a demo</a>
      </div>
    </div>
  </section>
</main>


@include('marketing.partials.footer')
</x-layouts::marketing>
