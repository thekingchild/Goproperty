{{--
    Paying a bill online (Http\Controllers\PayOnlineController::show): the
    agency, the bill, the amount, the payer's email for Paystack's receipt,
    and "Continue to Paystack". No tenant details, no scripts; inline
    styles only; works at 360 px.
--}}
@php
    /** @var \App\Branding\Branding $brand */
    /** @var \Modules\Billing\Contracts\BillBalance $bill */
    $name = $brand->displayName();
    $strong = \App\Branding\BrandColours::textShade($brand->primaryColor);
@endphp
<!DOCTYPE html>
<html lang="en-NG">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{{ __('Pay :amount online — :agency', ['amount' => $bill->outstanding->format(), 'agency' => $name]) }}</title>
<style>
  *, *::before, *::after { box-sizing: border-box; }
  body { margin: 0; background: #f1f5f9; color: #0f172a; font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; }
  .bar { height: 8px; background: {{ strtolower($brand->primaryColor) }}; }
  main { max-width: 520px; margin: 0 auto; padding: 24px 16px 40px; }
  .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 20px; margin-bottom: 16px; }
  h1 { font-size: 1.3rem; margin: 0 0 4px; overflow-wrap: anywhere; }
  .muted { color: #475569; font-size: .9rem; margin: 0; }
  .due { margin: 16px 0; padding: 16px; border-radius: 12px; background: {{ $strong }}; color: #fff; }
  .due span { display: block; font-size: .85rem; opacity: .9; }
  .due strong { display: block; font-size: 1.9rem; line-height: 1.2; overflow-wrap: anywhere; }
  label { display: block; font-weight: 600; margin-bottom: 6px; }
  input[type=email] { width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 10px; font: inherit; }
  .error { color: #b91c1c; font-size: .9rem; margin: 8px 0 0; }
  button { margin-top: 16px; width: 100%; padding: 14px; border: 0; border-radius: 10px; background: #0f172a; color: #fff; font: inherit; font-weight: 600; cursor: pointer; }
  a { color: {{ $strong }}; }
</style>
</head>
<body>
<div class="bar" aria-hidden="true"></div>
<main>
  <section class="card">
    <h1>{{ __('Pay :agency online', ['agency' => $name]) }}</h1>
    <p class="muted">{{ __('Bill :number. You pay through Paystack, straight into :agency\'s own account.', ['number' => $bill->number, 'agency' => $name]) }}</p>
    <div class="due" data-test="online-amount">
      <span>{{ __('Amount to pay') }}</span>
      <strong>{{ $bill->outstanding->format() }}</strong>
    </div>
    <form method="post" action="{{ $action }}">
      @csrf
      <label for="email">{{ __('Your email address') }}</label>
      <input type="email" id="email" name="email" value="{{ old('email') }}" required maxlength="150" autocomplete="email">
      <p class="muted" style="margin-top: 6px;">{{ __('Paystack sends your receipt here. :agency sends its own receipt once the payment is confirmed.', ['agency' => $name]) }}</p>
      @error('email')
        <p class="error" role="alert" data-test="online-error">{{ $message }}</p>
      @enderror
      <button type="submit">{{ __('Continue to Paystack') }}</button>
    </form>
  </section>
  <p class="muted" style="text-align: center;"><a href="{{ $back }}">{{ __('Back to the bill (bank transfer details)') }}</a></p>
</main>
</body>
</html>
