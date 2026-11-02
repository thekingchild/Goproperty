{{--
    Building Groups (/o/{organization}/groups; Modules\BuildingGroup\Livewire\GroupIndex,
    P07-02): one per building, open ones first. Rows stack into cards on
    phones (UX_DESIGN.md § 6).
--}}
<x-page :heading="__('Building Groups')" :description="__('Each building has its group: its tenants and your team post news, ask questions and talk to each other.')">
    <x-slot:actions>
        @if ($groups->isNotEmpty())
            <x-list-export list="building_groups" />
        @endif
    </x-slot:actions>

    @if ($groups->isEmpty())
        <x-empty-state icon="chat-bubble-left-right" :heading="__('No groups yet')" :description="__('A building gets its group as soon as it is added; its tenants join when their lease is active.')" />
    @else
        <ul class="divide-y divide-zinc-100 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-800 dark:border-zinc-700 dark:bg-zinc-900" data-test="groups-list">
            @foreach ($groups as $group)
                <li class="flex flex-col gap-2 p-4 sm:flex-row sm:items-center sm:justify-between" wire:key="group-{{ $group->id }}" data-group="{{ $group->id }}">
                    <div class="flex min-w-0 flex-col">
                        <a href="{{ route('workspace.groups.show', [$organization, $group->id]) }}" wire:navigate class="truncate font-medium text-zinc-900 hover:underline dark:text-white" data-test="group-link">{{ $list->nameOf($group) }}</a>
                        <span class="truncate text-sm text-zinc-500 dark:text-zinc-400">
                            {{ collect([
                                trans_choice(':count member|:count members', (int) $group->members_count),
                                trans_choice(':count post|:count posts', (int) $group->posts_count),
                                ($latest = \Modules\BuildingGroup\Lists\GroupList::latestActivity($group)) ? __('active :time', ['time' => \Modules\BuildingGroup\Feed\GroupDates::show($latest)]) : null,
                            ])->filter()->join(' · ') }}
                        </span>
                    </div>
                    <flux:badge size="sm" :color="$group->is_active ? 'green' : 'zinc'" data-test="group-status">{{ $group->is_active ? __('Open') : __('Closed') }}</flux:badge>
                </li>
            @endforeach
        </ul>

        <flux:pagination :paginator="$groups" />
    @endif
</x-page>
