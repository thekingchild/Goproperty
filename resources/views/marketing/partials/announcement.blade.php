{{-- site.announcement: dismissible ribbon above the nav, remembered per content (PLATFORM_CONSOLE.md § 9.3). --}}
@php($announcement = $theme->string('site.announcement'))
@if ($announcement !== '')
  @php($announcementLink = $theme->string('site.announcement_link'))
  <div class="announce" data-announcement="{{ substr(hash('sha256', $announcement.'|'.$announcementLink), 0, 12) }}">
    <p class="wrap announce__inner">
      @if ($announcementLink !== '')
        <a href="{{ $announcementLink }}">{{ $announcement }} <span aria-hidden="true">→</span></a>
      @else
        <span>{{ $announcement }}</span>
      @endif
      <button class="announce__close" type="button" aria-label="Dismiss announcement" data-announcement-close><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></button>
    </p>
  </div>
  {{-- Hidden before first paint once dismissed. --}}
  <script>try{const a=document.currentScript.previousElementSibling;if(localStorage.getItem('announcement')===a.dataset.announcement)a.hidden=true}catch(e){}</script>
@endif
