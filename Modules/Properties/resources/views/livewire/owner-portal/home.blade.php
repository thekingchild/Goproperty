{{--
    The owner portal's home (Modules\Properties\Livewire\OwnerPortal\OwnerHome,
    P03-15): one card per agency that manages buildings of the owner, in that
    agency's current colours and logo, with each building's units and
    occupancy. Mobile first (UX_DESIGN.md § 1: 360 px, 44 px targets).
    Read-only.
--}}
<x-page :heading="__('My properties')" :description="__('Your buildings, units, leases and bills, from every agency that manages them for you.')">
    @if ($agencies !== [] && Route::has('owner.statements.index'))
        <x-slot:actions>
            {{-- Monthly statements, renewals and settlements (Modules\OwnerStatements, P08-05). --}}
            <flux:button icon="document-text" :href="route('owner.statements.index')" wire:navigate class="min-h-11" data-test="owner-statements-link">{{ __('Statements') }}</flux:button>
        </x-slot:actions>
    @endif
    @if (session('status'))
        <flux:callout variant="success" icon="check-circle" :heading="session('status')" role="status" data-test="owner-status" />
    @endif

    @if ($agencies === [])
        <x-empty-state icon="key" :heading="__('No properties yet')" :description="__('When an agency invites you as the owner of buildings it manages, they appear here.')" />
    @else
        @foreach ($agencies as $section)
            <section class="flex flex-col gap-4 rounded-2xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-900/50" style="border-top: 4px solid {{ $section->agency->colour }}" data-test="owner-agency-card" data-organization="{{ $section->agency->organizationId }}">
                @include('properties::owner-portal.partials.agency', ['agency' => $section->agency])

                <div class="flex flex-col gap-3">
                    <flux:heading size="md" level="3">{{ __('My buildings') }}</flux:heading>
                    @forelse ($section->buildings as $building)
                        <a href="{{ route('owner.buildings.show', $building->id) }}" wire:navigate class="flex min-h-11 flex-col gap-1 rounded-xl border border-zinc-200 bg-white p-4 hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-900" data-test="owner-building" data-building="{{ $building->id }}">
                            <span class="flex flex-wrap items-center justify-between gap-2">
                                <span class="font-medium break-words text-zinc-900 dark:text-white">{{ $building->name }}</span>
                                @if ($building->archived)
                                    <flux:badge size="sm" color="zinc">{{ __('No longer managed') }}</flux:badge>
                                @endif
                            </span>
                            <span class="text-sm break-words text-zinc-600 dark:text-zinc-300">{{ $building->address }}</span>
                            <span class="text-sm text-zinc-600 dark:text-zinc-300" data-test="owner-occupancy">
                                {{ trans_choice(':count unit|:count units', $building->unitCount) }} · {{ __(':occupied occupied, :vacant vacant', ['occupied' => $building->occupied(), 'vacant' => $building->vacant()]) }}@if ($building->other() > 0), {{ __(':count other', ['count' => $building->other()]) }}@endif
                            </span>
                        </a>
                    @empty
                        <flux:text>{{ __('No buildings yet.') }}</flux:text>
                    @endforelse
                </div>
            </section>
        @endforeach
    @endif
</x-page>
