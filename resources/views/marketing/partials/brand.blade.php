{{--
    Brand link (PLATFORM_CONSOLE.md § 9.2): the generated mark and the theme
    name. tone "dark" sits on the dark hero (white mark), "light" on white.
    Uploaded logos replace the mark once the brand routes exist (P00-09c).
--}}
@php
    $logoRole = $tone === 'dark' ? 'logo-dark' : 'logo-light';
    $logoKey = $tone === 'dark' ? 'theme.logo_dark' : 'theme.logo_light';
    $hasLogo = Route::has('brand.asset') && $theme->string($logoKey) !== '';
@endphp
<a class="brand" href="{{ route('home') }}" aria-label="{{ $brand }} home">
  @if ($hasLogo)
    <img class="brand__logo" src="{{ route('brand.asset', $logoRole) }}" alt="" height="34">
  @else
    <x-brand-mark class="brand__mark" :tone="$tone === 'dark' ? 'light' : 'brand'" />
  @endif
  @if (! $hasLogo || $theme->string('theme.logo_mode') !== 'logo_only')
    <span>{{ $brand }}</span>
  @endif
</a>
