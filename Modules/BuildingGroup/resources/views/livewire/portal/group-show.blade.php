{{--
    A Building Group in the tenant portal (Modules\BuildingGroup\Livewire\Portal\PortalGroupShow,
    P07-02): the agency's header in its colours, then the feed. Phone
    first: one column, 44 px targets, no autofocus.
--}}
<x-page :heading="$name" :description="__('Your building\'s group: your neighbours and :agency.', ['agency' => $agency])">
    <x-slot:actions>
        <flux:button icon="arrow-left" :href="route('portal.groups.index')" wire:navigate class="min-h-11">{{ __('All groups') }}</flux:button>
    </x-slot:actions>

    <header class="flex min-w-0 items-center gap-3" data-test="portal-agency" data-organization="{{ $organizationId }}">
        <span class="flex size-10 shrink-0 items-center justify-center rounded-lg text-sm font-bold text-white" style="background-color: {{ $colour }}" aria-hidden="true">{{ $initials }}</span>
        <div class="min-w-0">
            <flux:heading size="lg" level="2" class="break-words">{{ $agency }}</flux:heading>
            @if ($readOnly)
                <flux:text class="text-sm" data-test="portal-read-only">{{ __('Read-only for now: :agency\'s account is on hold, so nobody can post here.', ['agency' => $agency]) }}</flux:text>
            @endif
        </div>
    </header>

    <div class="mx-auto flex w-full max-w-2xl flex-col">
        @include('buildinggroup::partials.feed')
    </div>
</x-page>
