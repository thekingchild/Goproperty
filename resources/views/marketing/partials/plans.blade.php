{{-- Plan cards from the plan catalogue (BILLING_PAYMENTS.md § 1.1); never typed into settings. --}}
<div class="plans reveal" data-plans>
  @foreach ($plans->plans() as $plan)
    <article @class(['plan', 'plan--featured' => $plan->featured, 'is-match' => $plan->key === $calcPlan->key]) data-plan="{{ $plan->key }}">
      @if ($plan->featured)
        <span class="plan__flag">Most popular</span>
      @endif
      <div><h3>{{ $plan->name }}</h3><p class="plan__for">{{ $plan->audience }}</p></div>
      <p class="plan__price" data-price-month="{{ $plan->base->format() }}" data-price-year="{{ $plan->custom ? 'Custom' : $plan->base->times($plans->monthsPaidPerYear())->format() }}">{{ $plan->base->format() }} <small>/ month</small></p>
      @if ($plan->custom)
        <p class="plan__units">Starting price · {{ number_format($plan->includedUnits) }} units included</p>
      @else
        <p class="plan__units">{{ number_format($plan->includedUnits) }} units included · {{ $plan->extraUnit?->format() }} per extra unit</p>
      @endif
      <ul>
        @foreach ($plan->features as $feature)
          <li><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 5 5L20 7"/></svg>{{ str_replace(':brand', $brand, $feature) }}</li>
        @endforeach
      </ul>
      @if ($plan->custom)
        <a class="btn btn--dark" href="{{ $demoUrl }}">Talk to us</a>
      @else
        <a @class(['btn', 'btn--white' => $plan->featured, 'btn--ghost' => ! $plan->featured]) href="{{ $trialUrl }}">Start free trial</a>
      @endif
    </article>
  @endforeach
</div>
