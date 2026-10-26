{{-- Privacy policy and terms (site.privacy_policy, site.terms): sanitised HTML from the console. --}}
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
  </article>
</main>

@include('marketing.partials.footer')
</x-layouts::marketing>
