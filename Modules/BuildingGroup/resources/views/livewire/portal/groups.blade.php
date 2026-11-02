{{--
    Building Group in the tenant portal (Modules\BuildingGroup\Livewire\Portal\PortalGroups,
    P07-02): the groups of the buildings I live in, each with its agency.
    Phone first: cards, 44 px targets.
--}}
<x-page :heading="__('Building Group')" :description="__('Talk with your neighbours and your agency about your building.')">
    <x-portal.nav />

    @if ($groups === [])
        <x-empty-state icon="chat-bubble-left-right" :heading="__('No group yet')" :description="__('When your lease is active and your agency uses Building Group, your building\'s group shows here.')" data-test="portal-groups-empty" />
    @else
        <div class="flex flex-col gap-3" data-test="portal-groups">
            @foreach ($groups as $group)
                <a href="{{ route('portal.groups.show', $group->id) }}" wire:navigate wire:key="portal-group-{{ $group->id }}"
                    class="flex min-h-11 items-center gap-3 rounded-2xl border border-zinc-200 bg-white p-4 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:bg-zinc-800"
                    data-test="portal-group" data-group="{{ $group->id }}" data-organization="{{ $group->organizationId }}">
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-lg text-sm font-bold text-white" style="background-color: {{ $group->colour }}" aria-hidden="true">{{ $group->initials }}</span>
                    <span class="flex min-w-0 flex-col">
                        <span class="truncate font-medium text-zinc-900 dark:text-white">{{ $group->name }}</span>
                        <span class="truncate text-sm text-zinc-500 dark:text-zinc-400">
                            {{ collect([$group->agency, trans_choice(':count post|:count posts', $group->posts), $group->latestActivity ? __('active :time', ['time' => \Modules\BuildingGroup\Feed\GroupDates::show($group->latestActivity)]) : null])->filter()->join(' · ') }}
                        </span>
                    </span>
                    <flux:icon name="chevron-right" class="ms-auto size-5 shrink-0 text-zinc-400" />
                </a>
            @endforeach
        </div>
    @endif
</x-page>
