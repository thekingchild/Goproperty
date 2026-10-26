{{--
    Footer: theme.logo_light (or the generated mark), site.footer_text and
    site.social_links. Only links that lead somewhere are shown; help centre,
    news and receipt verification join when those pages exist.
--}}
@php
    $socialLabels = ['x' => 'on X', 'linkedin' => 'on LinkedIn', 'instagram' => 'on Instagram', 'facebook' => 'on Facebook', 'youtube' => 'on YouTube', 'whatsapp' => 'on WhatsApp'];
    $anchor = fn (string $id): string => request()->routeIs('home') ? '#'.$id : route('home').'#'.$id;
@endphp
<footer class="footer">
  <div class="wrap">
    <div class="footer__top">
      <div class="footer__about">
        @include('marketing.partials.brand', ['tone' => 'light'])
        <p>{{ $theme->string('site.footer_text') }}</p>
        @if ($socialLinks !== [])
          <div class="socials">
            @foreach ($socialLinks as $network => $url)
              <a href="{{ $url }}" rel="noopener" aria-label="{{ $network === 'whatsapp' ? 'Chat with '.$brand.' on WhatsApp' : $brand.' '.$socialLabels[$network] }}">
                @switch($network)
                  @case('x')<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.8 3h3.1l-6.8 7.8L22 21h-6.2l-4.9-6.4L5.3 21H2.2l7.3-8.3L2 3h6.4l4.4 5.8zm-1.1 16.2h1.7L7.4 4.7H5.6z"/></svg>@break
                  @case('linkedin')<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M4.98 3.5a2.5 2.5 0 1 1 0 5 2.5 2.5 0 0 1 0-5zM3 9h4v12H3zM9 9h3.8v1.7h.1c.5-1 1.8-2 3.8-2 4 0 4.8 2.6 4.8 6V21h-4v-5.6c0-1.3 0-3-1.9-3s-2.1 1.4-2.1 2.9V21H9z"/></svg>@break
                  @case('instagram')<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1" fill="currentColor"/></svg>@break
                  @case('facebook')<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M14 8.5V6.8c0-.8.5-1.3 1.3-1.3H17V2.2h-2.6C11.6 2.2 10 3.9 10 6.6v1.9H7.5V12H10v10h4V12h2.7l.5-3.5z"/></svg>@break
                  @case('youtube')<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M22 8.2a3 3 0 0 0-2.1-2.1C18 5.6 12 5.6 12 5.6s-6 0-7.9.5A3 3 0 0 0 2 8.2 31 31 0 0 0 1.6 12 31 31 0 0 0 2 15.8a3 3 0 0 0 2.1 2.1c1.9.5 7.9.5 7.9.5s6 0 7.9-.5a3 3 0 0 0 2.1-2.1 31 31 0 0 0 .4-3.8 31 31 0 0 0-.4-3.8zM10 15.2V8.8l5.2 3.2z"/></svg>@break
                  @case('whatsapp')<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><path d="M3 21l1.7-5A8.5 8.5 0 1 1 8 19.4z"/></svg>@break
                @endswitch
              </a>
            @endforeach
          </div>
        @endif
      </div>
      <div><h4>Product</h4><ul><li><a href="{{ $anchor('features') }}">Features</a></li><li><a href="{{ $anchor('payments') }}">Payments</a></li><li><a href="{{ $anchor('pricing') }}">Pricing</a></li><li><a href="{{ $anchor('faq') }}">FAQ</a></li></ul></div>
      <div><h4>Company</h4><ul><li><a href="{{ $demoUrl }}">Book a demo</a></li>@if ($theme->string('theme.support_email') !== '')<li><a href="mailto:{{ $theme->string('theme.support_email') }}">Contact</a></li>@endif<li><a href="{{ route('login') }}">Log in</a></li></ul></div>
      <div><h4>Legal</h4><ul><li><a href="{{ route('privacy') }}">Privacy</a></li><li><a href="{{ route('terms') }}">Terms</a></li></ul></div>
    </div>
    <div class="footer__bottom"><span>© {{ now()->year }} {{ $theme->string('theme.legal_name', $brand) }}. All rights reserved.</span><span>Made in Lagos for Nigeria.</span></div>
  </div>
</footer>
