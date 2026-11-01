{{--
    The Transaction Tier banner (P06-09, ONBOARDING_COMPLIANCE.md § 5.5,
    App\Compliance\Monitoring\TierStanding): "restricted" to every member;
    "upgrade required" and "approaching your limit" to those who see the
    organization's Transaction Tier (owners), with the way to upgrade.
--}}
@php
    $user = auth()->user();
    $standing = app(\App\Compliance\Monitoring\TierStanding::class)->current();
    $owner = $standing !== null && $user instanceof \App\Models\User && $user->can('viewAny', \App\Models\TierApplication::class);
    $context = app(\App\Tenancy\OrganizationContext::class);
    $url = $owner && Route::has('workspace.compliance.tier') ? route('workspace.compliance.tier', $context->get()) : null;
@endphp
@if ($standing !== null && $standing['state'] === \App\Compliance\Monitoring\TierStanding::RESTRICTED)
    <flux:callout variant="danger" icon="no-symbol" class="mb-6" role="status" data-test="tier-restricted-notice"
        :heading="__('This organization is restricted')">
        <flux:callout.text>
            {{ $standing['staff'] ? __("GoProperty's compliance team has restricted it.") : __('Its yearly collections are over its Transaction Tier limit and the grace period has ended.') }}
            {{ __("New leases, buildings and units cannot be added, and online payment is paused. Bills, payments, receipts and your tenants' portal keep working.") }}
            @if ($url && ! $standing['staff'])<a href="{{ $url }}" class="font-medium underline">{{ __('Upgrade tier') }}</a>@endif
        </flux:callout.text>
    </flux:callout>
@elseif ($owner && $standing['state'] === \App\Compliance\Monitoring\TierStanding::UPGRADE_REQUIRED)
    <flux:callout variant="danger" icon="exclamation-triangle" class="mb-6" role="status" data-test="tier-upgrade-notice"
        :heading="__('Upgrade required: you have reached your Transaction Tier limit')">
        <flux:callout.text>
            @if ($standing['amount'] && $standing['limit']){{ __(':amount collected over the last 12 months, over the :limit allowed.', ['amount' => $standing['amount']->format(), 'limit' => $standing['limit']->format()]) }} @endif
            @if ($standing['application_sent'])
                {{ __('Your upgrade application is with compliance: nothing is restricted while it is reviewed.') }}
            @elseif ($standing['grace_ends_on'])
                {{ __('Apply for a higher tier by :date, or the organization will be restricted.', ['date' => $standing['grace_ends_on']->format('j F Y')]) }}
            @endif
            @if ($url)<a href="{{ $url }}" class="font-medium underline">{{ __('Upgrade tier') }}</a>@endif
        </flux:callout.text>
    </flux:callout>
@elseif ($owner && $standing['state'] === \App\Compliance\Monitoring\TierStanding::APPROACHING && ! $standing['application_sent'])
    <flux:callout variant="warning" icon="chart-bar" class="mb-6" role="status" data-test="tier-approaching-notice"
        :heading="__('You are approaching your Transaction Tier limit')">
        <flux:callout.text>
            @if ($standing['forecast'] && $standing['limit'])
                {{ __('Your leases will bill more than the :limit a year your tier allows.', ['limit' => $standing['limit']->format()]) }}
            @elseif ($standing['amount'] && $standing['limit'])
                {{ __(':amount collected over the last 12 months, of the :limit allowed.', ['amount' => $standing['amount']->format(), 'limit' => $standing['limit']->format()]) }}
            @endif
            {{ __('Apply for a higher tier early so nothing is held up.') }}
            @if ($url)<a href="{{ $url }}" class="font-medium underline">{{ __('Upgrade tier') }}</a>@endif
        </flux:callout.text>
    </flux:callout>
@endif
