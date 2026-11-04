{{--
    Privacy policy and terms (site.privacy_policy, site.terms): sanitised HTML
    from the console. The privacy page adds, in fixed wording, what the
    landlord of a tenant's building sees (P03-16; P09-02) and the data
    protection officer (privacy.dpo_*); both pages end with the version a
    consent record names (App\Privacy\LegalDocuments).
--}}
<x-layouts::marketing :title="$heading" class="legal-page">
<div class="legal-top">
  <div class="wrap legal-top__inner">
    @include('marketing.partials.brand', ['tone' => 'light'])
    <a class="btn btn--ghost" href="{{ route('home') }}">Back to home</a>
  </div>
</div>

<main id="main" tabindex="-1" class="legal">
  <article class="wrap legal__body">
    {!! $body !!}

    @if ($privacy)
      <section data-test="landlord-sees">
        <h3>What the landlord of your building sees</h3>
        <p>If you rent a home managed through {{ $brand }}, the landlord who owns your building can see, in {{ $brand }}'s owner portal: your name, phone number, email address, home address, occupation and emergency contact; your KYC status and the type of ID you gave, with only the last four characters of its number; your leases; your payments and receipts; and your deposit. The agency that manages the building sees the same, and more, to manage your tenancy.</p>
      </section>

      <section data-test="dpo">
        <h3>Data protection officer</h3>
        @if ($dpo['name'] !== null || $dpo['email'] !== null)
          <p>
            @if ($dpo['name'] !== null)<strong>{{ $dpo['name'] }}</strong><br>@endif
            @if ($dpo['email'] !== null)<a href="mailto:{{ $dpo['email'] }}">{{ $dpo['email'] }}</a><br>@endif
            @if ($dpo['phone'] !== null){{ $dpo['phone'] }}<br>@endif
            @if ($dpo['address'] !== null){{ $dpo['address'] }}@endif
          </p>
        @elseif ($theme->string('theme.support_email') !== '')
          <p>Write to <a href="mailto:{{ $theme->string('theme.support_email') }}">{{ $theme->string('theme.support_email') }}</a> and ask for the data protection officer.</p>
        @else
          <p>Contact {{ $brand }} support and ask for the data protection officer.</p>
        @endif
        <p>To ask for a copy of your data or for its erasure, write to the data protection officer, or to the agency or landlord that manages your building. You may also complain to the Nigeria Data Protection Commission.</p>
      </section>
    @endif

    <p class="legal__version" data-test="legal-version"><small>Version {{ $version }}</small></p>
  </article>
</main>

@include('marketing.partials.footer')
</x-layouts::marketing>
