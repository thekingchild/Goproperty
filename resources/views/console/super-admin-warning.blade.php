{{-- PLATFORM_CONSOLE.md § 1: at least two active super-admins must exist. --}}
<div role="alert" data-super-admin-warning
     style="margin-bottom:1rem;border:1px solid #f59e0b;border-radius:.75rem;background:#fffbeb;color:#78350f;padding:.75rem 1rem;font-size:.875rem">
    @if ($count === 0)
        <strong>No active super-admin.</strong> Nobody can manage staff roles until a super-admin is active again.
    @else
        <strong>Only one active super-admin.</strong> Make a second staff member super-admin (Staff → open their profile → Grant role), so the console cannot be locked out.
    @endif
</div>
