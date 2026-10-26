{{-- Shown on every workspace page while the organization refuses writes (App\Tenancy\OrganizationWriteGuard). --}}
@php($guard = app(\App\Tenancy\OrganizationWriteGuard::class))
@php($blocked = $guard->blockedOrganization())
@if ($blocked !== null)
    <flux:callout variant="warning" icon="lock-closed" class="mb-6" role="status"
        :heading="$blocked->status === \App\Enums\OrganizationStatus::Cancelled ? __('This organization is closed') : __('This organization is suspended')"
        :text="$guard->message($blocked).' '.__('Contact support if you think this is a mistake.')" />
@endif
