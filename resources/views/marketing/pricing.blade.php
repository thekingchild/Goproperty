{{--
    Pricing page (P06-11; UX_DESIGN.md § 4, BILLING_PAYMENTS.md § 1.1–1.2):
    the landing page's calculator and plan cards, then every offered plan
    side by side. Every price, limit and switch comes from the plan tables
    through App\Marketing\PlanCatalogue (today's terms), never from the page.
    "Start free trial" carries the plan and billing period into signup.
--}}
@php
    $offered = $plans->plans();
    $months = $plans->monthsPaidPerYear();
    $yes = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 5 5L20 7"/></svg>';
@endphp
<x-layouts::marketing title="Pricing" :script="true" class="pricing-page">
@include('marketing.partials.nav')

<header class="site-top site-top--page">
  <div class="site-top__shade" aria-hidden="true"></div>
  <div class="nav-spacer" aria-hidden="true"></div>
  <div class="wrap page-head">
    <p class="eyebrow">Pricing</p>
    <h1 class="h2 page-head__title" id="pricing-title">Priced per unit. <b>Less than 1% of the rent.</b></h1>
    <p class="lede">A base price that includes some units, then a small fee for each extra one. No fee on payments. Every plan starts with a {{ $plans->trialDays() }}-day free trial — no card.</p>
  </div>
</header>

<main id="main" tabindex="-1">
  <section class="section section--tight-top" aria-labelledby="pricing-title">
    <div class="wrap">
      @include('marketing.partials.calculator')
      @include('marketing.partials.plans')
      <p class="price-note">Prices exclude {{ $plans->vatPercent() }}% VAT. Units are blocks/apartments, counted on your billing day. Yearly plans: pay {{ $months }} months, get 12.</p>
    </div>
  </section>

  <section class="section section--canvas" aria-labelledby="compare-title">
    <div class="wrap">
      <div class="reveal">
        <p class="eyebrow">Compare plans</p>
        <h2 class="h2" id="compare-title">Everything in each plan, <b>side by side.</b></h2>
        <p class="lede">Every plan includes properties, units, tenants, leases and bills; your own branded invoices and receipts; the tenant and owner portals; bank transfer with statement matching and proof upload; Excel import with a dry run; maintenance tickets and email reminders.</p>
      </div>

      <div class="compare reveal" role="region" aria-labelledby="compare-title" tabindex="0">
        <table class="compare__table" data-test="compare">
          <thead>
            <tr>
              <th scope="col"><span class="sr-only">What you get</span></th>
              @foreach ($offered as $plan)
                <th scope="col" @class(['is-featured' => $plan->featured]) data-plan-column="{{ $plan->key }}">{{ $plan->name }}</th>
              @endforeach
            </tr>
          </thead>
          <tbody>
            <tr class="compare__group"><th scope="rowgroup" colspan="{{ count($offered) + 1 }}">Price</th></tr>
            <tr>
              <th scope="row">Base price a month</th>
              @foreach ($offered as $plan)
                <td>{{ $plan->custom ? 'from ' : '' }}{{ $plan->base->format() }}</td>
              @endforeach
            </tr>
            <tr>
              <th scope="row">Base price a year</th>
              @foreach ($offered as $plan)
                <td>{{ $plan->custom ? 'Custom contract' : $plan->base->times($months)->format() }}</td>
              @endforeach
            </tr>
            <tr>
              <th scope="row">Units included</th>
              @foreach ($offered as $plan)
                <td>{{ number_format($plan->includedUnits) }}</td>
              @endforeach
            </tr>
            <tr>
              <th scope="row">Each extra unit a month</th>
              @foreach ($offered as $plan)
                <td>{{ $plan->extraUnit?->format() ?? 'Custom' }}</td>
              @endforeach
            </tr>
            <tr>
              <th scope="row">Free trial</th>
              @foreach ($offered as $plan)
                <td>{{ $plan->trialDays }} days</td>
              @endforeach
            </tr>

            <tr class="compare__group"><th scope="rowgroup" colspan="{{ count($offered) + 1 }}">Features and limits</th></tr>
            @foreach ($features as $feature)
              <tr data-feature="{{ $feature->value }}">
                <th scope="row">{{ $feature === \App\Plans\Feature::RemovePoweredBy ? 'Remove "Generated with '.$brand.'" from documents' : $feature->label() }}</th>
                @foreach ($offered as $plan)
                  @php $value = $plan->feature($feature); @endphp
                  @if ($feature->isLimit())
                    <td>{{ $value->describe($feature) }}</td>
                  @elseif ($value->enabled)
                    <td class="is-yes">{!! $yes !!}<span class="sr-only">Included</span></td>
                  @else
                    <td class="is-no"><span aria-hidden="true">—</span><span class="sr-only">Not included</span></td>
                  @endif
                @endforeach
              </tr>
            @endforeach
          </tbody>
          <tfoot>
            <tr>
              <th scope="row"><span class="sr-only">Start</span></th>
              @foreach ($offered as $plan)
                <td>
                  @if ($plan->custom)
                    <a class="btn btn--dark" href="{{ $demoUrl }}">Talk to us</a>
                  @else
                    <a @class(['btn', 'btn--primary' => $plan->featured, 'btn--ghost' => ! $plan->featured]) href="{{ route('register', ['plan' => $plan->key, 'cycle' => 'monthly']) }}" data-href-month="{{ route('register', ['plan' => $plan->key, 'cycle' => 'monthly']) }}" data-href-year="{{ route('register', ['plan' => $plan->key, 'cycle' => 'annual']) }}">Start free trial</a>
                  @endif
                </td>
              @endforeach
            </tr>
          </tfoot>
        </table>
      </div>
      <p class="price-note">Prices exclude {{ $plans->vatPercent() }}% VAT. Extra SMS credits can be bought in the app. Your Transaction Tier (how much rent you can manage) is separate from your plan.</p>
    </div>
  </section>

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
