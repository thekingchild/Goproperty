{{--
    A tenant of the owner's buildings (Modules\Properties\Livewire\OwnerPortal\OwnerTenantShow,
    P03-16; product owner 2026-10-29): name, phone, email, address,
    occupation, emergency contact, KYC status, ID type and the ID number
    masked to its last four characters (never in full), and the tenant's
    leases in the owner's buildings. Read-only.
--}}
@php
    /** @var \Modules\Properties\OwnerPortal\OwnerTenantPage $page */
    $tenant = $page->tenant;
@endphp
<x-page :heading="$tenant->name" :description="__('Tenant')">
    @if ($page->leases !== [])
        <x-slot:actions>
            <flux:button icon="arrow-left" :href="route('owner.buildings.show', $page->leases[0]->lease->propertyId)" wire:navigate class="min-h-11">{{ $page->leases[0]->building }}</flux:button>
        </x-slot:actions>
    @endif

    <section class="flex flex-col gap-4 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" style="border-top: 6px solid {{ $page->agency->colour }}" data-test="owner-tenant-page" data-tenant="{{ $tenant->id }}">
        @include('properties::owner-portal.partials.agency', ['agency' => $page->agency])

        <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2" data-test="owner-tenant-details">
            <div>
                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Phone number') }}</dt>
                <dd class="break-words text-zinc-900 dark:text-white" data-test="owner-tenant-phone">{{ $tenant->phone }}</dd>
            </div>
            <div>
                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Email address') }}</dt>
                <dd class="break-all text-zinc-900 dark:text-white" data-test="owner-tenant-email">{{ $tenant->email ?? __('Not given') }}</dd>
            </div>
            <div>
                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Address') }}</dt>
                <dd class="break-words text-zinc-900 dark:text-white" data-test="owner-tenant-address">{{ $tenant->address ?? __('Not given') }}</dd>
            </div>
            <div>
                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Occupation') }}</dt>
                <dd class="break-words text-zinc-900 dark:text-white" data-test="owner-tenant-occupation">{{ $tenant->occupation ?? __('Not given') }}</dd>
            </div>
            <div>
                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Emergency contact') }}</dt>
                <dd class="break-words text-zinc-900 dark:text-white" data-test="owner-tenant-emergency">
                    @if ($tenant->hasEmergencyContact())
                        {{ collect([$tenant->emergencyName, $tenant->emergencyRelationship ? '('.$tenant->emergencyRelationship.')' : null, $tenant->emergencyPhone])->filter()->implode(' ') }}
                    @else
                        {{ __('None on file') }}
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('KYC status') }}</dt>
                <dd><flux:badge size="sm" :color="$tenant->kycStatusColor" data-test="owner-tenant-kyc">{{ __($tenant->kycStatusLabel) }}</flux:badge></dd>
            </div>
            <div>
                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('ID') }}</dt>
                <dd class="text-zinc-900 dark:text-white" data-test="owner-tenant-id">{{ $tenant->idNumberMasked !== null ? __($tenant->idTypeLabel).' '.$tenant->idNumberMasked : ($tenant->idType === 'none' ? __('No ID on file') : __($tenant->idTypeLabel)) }}</dd>
            </div>
        </dl>
        @if ($tenant->archived)
            <flux:text class="text-sm">{{ __(':agency no longer has this tenant on its active list.', ['agency' => $page->agency->name]) }}</flux:text>
        @endif
    </section>

    <section class="flex flex-col gap-3" data-test="owner-tenant-leases">
        <flux:heading size="lg" level="2">{{ __('Leases in your buildings') }}</flux:heading>
        @foreach ($page->leases as $home)
            <a href="{{ route('owner.leases.show', $home->lease->id) }}" wire:navigate class="flex min-h-11 flex-col gap-1 rounded-xl border border-zinc-200 bg-white p-4 hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-900" data-test="owner-lease-link" data-lease="{{ $home->lease->id }}">
                <span class="flex flex-wrap items-center justify-between gap-2">
                    <span class="font-medium break-words text-zinc-900 dark:text-white">{{ $home->unit }} · {{ $home->building }}</span>
                    <flux:badge size="sm" :color="$home->statusColor()">{{ __($home->lease->statusLabel) }}</flux:badge>
                </span>
                <span class="text-sm text-zinc-600 dark:text-zinc-300">{{ $home->lease->startDate->format('d/m/Y') }} – {{ $home->lease->endDate->format('d/m/Y') }} · {{ __(':amount :cycle', ['amount' => $home->lease->rent->format(), 'cycle' => strtolower(__($home->lease->cycle->label()))]) }}</span>
            </a>
        @endforeach
    </section>
</x-page>
