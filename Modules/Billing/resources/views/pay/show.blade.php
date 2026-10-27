{{--
    The public pay page of one bill (Modules\Billing\Http\Controllers\PayPageController;
    BRANDING_DOCUMENTS.md § 1 "Pay page"): the agency's branding as on the
    invoice, the bill, the amount outstanding, the agency's bank account
    with the payment reference for the narration, and the online payment
    options (Contracts\PaymentOptions). No tenant details, no scripts.
    Inline styles only (the sign-in pages' security policy); works at 360 px.
--}}
@php
    /** @var \Modules\Billing\Pay\PayPage $page */
    $brand = $page->brand;
    $name = $brand->name();
    $tone = $page->isVoid() ? 'void' : ($page->isPaid() ? 'paid' : $page->status->value);
@endphp
<!DOCTYPE html>
<html lang="en-NG">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{{ __('Pay :number', ['number' => $page->number]) }} · {{ $name }}</title>
<style>
  *, *::before, *::after { box-sizing: border-box; }
  body { margin: 0; background: #f1f5f9; color: #0f172a; font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; }
  .bar { height: 8px; background: {{ $brand->primary() }}; }
  main { max-width: 560px; margin: 0 auto; padding: 24px 16px 40px; }
  .agency { display: flex; align-items: center; gap: 12px; margin-bottom: 20px; }
  .agency img { max-height: 48px; max-width: 160px; }
  .agency .monogram { width: 44px; height: 44px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; background: {{ $brand->strong() }}; color: #fff; font-weight: 700; }
  .agency strong { display: block; font-size: 1.1rem; overflow-wrap: anywhere; }
  .agency div span { color: #475569; font-size: .875rem; }
  .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 20px; margin-bottom: 16px; }
  h1 { font-size: 1.35rem; margin: 0 0 4px; }
  .muted { color: #475569; font-size: .9rem; margin: 0; }
  .pill { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: .78rem; font-weight: 700; letter-spacing: .03em; text-transform: uppercase; }
  .pill--unpaid { background: #fef3c7; color: #92400e; }
  .pill--partially_paid { background: #dbeafe; color: #1e40af; }
  .pill--overdue { background: #fee2e2; color: #991b1b; }
  .pill--paid { background: #dcfce7; color: #166534; }
  .pill--void { background: #1e293b; color: #fff; }
  .due { margin-top: 16px; padding: 16px; border-radius: 12px; background: {{ $brand->strong() }}; color: #fff; }
  .due span { display: block; font-size: .85rem; opacity: .9; }
  .due strong { display: block; font-size: 1.9rem; line-height: 1.2; overflow-wrap: anywhere; }
  dl { margin: 0; }
  .facts div { display: flex; justify-content: space-between; gap: 12px; padding: 8px 0; border-top: 1px solid #f1f5f9; }
  .facts div:first-child { border-top: 0; }
  dt { color: #475569; }
  dd { margin: 0; font-weight: 600; text-align: right; overflow-wrap: anywhere; }
  .lines { list-style: none; margin: 0; padding: 0; }
  .lines li { display: flex; justify-content: space-between; gap: 12px; padding: 10px 0; border-top: 1px solid #f1f5f9; }
  .lines li:first-child { border-top: 0; }
  .lines small { display: block; color: #64748b; }
  h2 { font-size: 1rem; margin: 0 0 12px; }
  .reference { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 1.25rem; letter-spacing: .06em; color: {{ $brand->strong() }}; }
  .note { font-size: .875rem; color: #475569; margin: 12px 0 0; }
  .notice { border-left: 4px solid #1e293b; }
  .notice--paid { border-left-color: #15803d; }
  footer { text-align: center; color: #64748b; font-size: .8rem; margin-top: 24px; }
</style>
</head>
<body>
<div class="bar" aria-hidden="true"></div>
<main data-status="{{ $tone }}">
  <header class="agency">
    @if ($brand->logoSrc)
      <img src="{{ $brand->logoSrc }}" alt="{{ $name }}">
    @else
      <span class="monogram" aria-hidden="true">{{ $brand->initials() }}</span>
    @endif
    <div>
      <strong>{{ $name }}</strong>
      @if ($brand->branding->phone || $brand->branding->email)
        <span>{{ implode(' · ', array_filter([$brand->branding->phone, $brand->branding->email])) }}</span>
      @endif
    </div>
  </header>

  @if ($page->isVoid())
    <section class="card notice" data-test="pay-void">
      <h1>{{ __('This bill is void') }}</h1>
      <p class="muted">{{ __('Bill :number was cancelled by :agency:date. Do not pay it. Ask :agency for the bill you should pay.', ['number' => $page->number, 'agency' => $name, 'date' => $page->voidedOn ? ' '.__('on :date', ['date' => $page->voidedOn]) : '']) }}</p>
    </section>
  @else
    <section class="card" data-test="pay-summary">
      <p class="muted">{{ __('Bill') }} {{ $page->number }} · <span class="pill pill--{{ $tone === 'paid' ? 'paid' : $page->status->value }}" data-test="pay-status">{{ __($tone === 'paid' ? 'Paid' : $page->status->label()) }}</span></p>
      <h1>{{ $page->isPaid() ? __('This bill is paid') : __('Pay your bill') }}</h1>
      @if ($page->property)
        <p class="muted">{{ $page->property }}</p>
      @endif

      @if ($page->asksForMoney())
        <div class="due" data-test="pay-outstanding">
          <span>{{ __('Amount to pay') }}</span>
          <strong>{{ $page->outstanding->format() }}</strong>
          <span>{{ __('Due :date', ['date' => $page->dueOn]) }}</span>
        </div>
      @endif

      <dl class="facts" style="margin-top: 16px">
        <div><dt>{{ __('Issued') }}</dt><dd>{{ $page->issuedOn }}</dd></div>
        <div><dt>{{ __('Due') }}</dt><dd>{{ $page->dueOn }}</dd></div>
        @if ($page->period)
          <div><dt>{{ __('Period') }}</dt><dd>{{ $page->period }}</dd></div>
        @endif
        <div><dt>{{ __('Total') }}</dt><dd>{{ $page->amount->format() }}</dd></div>
        <div><dt>{{ __('Paid so far') }}</dt><dd data-test="pay-paid">{{ $page->paid->format() }}</dd></div>
      </dl>
    </section>

    <section class="card" data-test="pay-lines">
      <h2>{{ __('What this bill is for') }}</h2>
      <ul class="lines">
        @foreach ($page->lines as $line)
          <li>
            <span>{{ $line['label'] }}@if ($line['period'])<small>{{ $line['period'] }}</small>@endif</span>
            <b>{{ $line['amount'] }}</b>
          </li>
        @endforeach
      </ul>
    </section>

    @if ($page->asksForMoney())
      <section class="card" data-test="pay-bank">
        <h2>{{ __('Pay by bank transfer') }}</h2>
        @if ($page->bank)
          <dl class="facts">
            <div><dt>{{ __('Bank') }}</dt><dd>{{ $page->bank->bank }}</dd></div>
            <div><dt>{{ __('Account name') }}</dt><dd>{{ $page->bank->accountName }}</dd></div>
            <div><dt>{{ __('Account number') }}</dt><dd data-test="pay-account">{{ $page->bank->accountNumber }}</dd></div>
            <div><dt>{{ __('Narration') }}</dt><dd class="reference" data-test="pay-reference">{{ $page->reference }}</dd></div>
          </dl>
          <p class="note">{{ __('Put :reference in your transfer narration so :agency can match your payment to this bill.', ['reference' => $page->reference, 'agency' => $name]) }}</p>
        @else
          <p class="muted" data-test="pay-no-bank">{{ __(':agency has not added a bank account yet. Contact them for payment details and quote :reference.', ['agency' => $name, 'reference' => $page->reference]) }}</p>
        @endif
      </section>

      @if ($page->options !== [])
        <section class="card" data-test="pay-online">
          <h2>{{ __('Pay online') }}</h2>
          @foreach ($page->options as $option)
            {!! $option !!}
          @endforeach
        </section>
      @endif
    @else
      <section class="card notice notice--paid" data-test="pay-nothing-due">
        <p class="muted">{{ __('Nothing is owed on this bill. Keep your receipt from :agency.', ['agency' => $name]) }}</p>
      </section>
    @endif
  @endif

  <footer>
    {{ __('Money goes straight to :agency\'s own account.', ['agency' => $name]) }}
    @if ($brand->showPoweredBy && $brand->productName !== '')
      <br>{{ __('Generated with :product', ['product' => $brand->productName]) }}
    @endif
  </footer>
</main>
</body>
</html>
