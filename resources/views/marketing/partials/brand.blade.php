{{--
    Brand link (PLATFORM_CONSOLE.md § 9.2): the generated mark and the theme
    name. tone "dark" sits on the dark hero (white mark), "light" on white.
    An uploaded logo replaces the mark; with logo_only the name is hidden.
--}}
@php
    $logo = app(\App\Theme\BrandAssets::class)->url($theme, $tone === 'dark' ? 'logo-dark' : 'logo-light');
@endphp
<a class="brand" href="{{ route('home') }}" aria-label="{{ $brand }} home">
  @if ($logo !== null)
    <img class="brand__logo" src="{{ $logo }}" alt="" height="34">
  @else
    <x-brand-mark class="brand__mark" :tone="$tone === 'dark' ? 'light' : 'brand'" />
  @endif
  @if ($logo === null || $theme->string('theme.logo_mode') !== 'logo_only')
    <span>{{ $brand }}</span>
  @endif
</a>
