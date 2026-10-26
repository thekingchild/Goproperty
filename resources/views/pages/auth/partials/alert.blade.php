{{-- Error (default) or information alert for the sign-in pages. --}}
<div @class(['alert', 'alert--info' => ($tone ?? 'error') === 'info']) role="{{ ($tone ?? 'error') === 'info' ? 'status' : 'alert' }}" data-error>
  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/></svg>
  <span>{{ $message }}</span>
</div>
