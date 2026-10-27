{{--
    The Paystack option on a bill's pay page (PayOnline\PaystackOption):
    inline styles only (the pay page's security policy), no script.
--}}
<p class="muted">{{ __('Pay by card, bank transfer or USSD. The money goes straight to the agency\'s own Paystack account.') }}</p>
<p style="margin: 14px 0 0;">
  <a href="{{ $url }}" data-test="paystack-pay" style="display: inline-block; padding: 12px 20px; border-radius: 10px; background: #0f172a; color: #fff; font-weight: 600; text-decoration: none;">{{ __('Pay :amount online', ['amount' => $amount]) }}</a>
</p>
