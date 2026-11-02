{{-- The pricing calculator and the Monthly/Yearly toggle (UX_DESIGN.md § 4 section 8), on the landing and pricing pages; resources/js/marketing.js runs them. --}}
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
