{{--
    A Building Group (Modules\BuildingGroup\Livewire\GroupShow, P07-02): the
    group's name, its members now and whether it is open, then the feed.
--}}
<x-page :heading="$name" :description="trans_choice(':count tenant is a member now.|:count tenants are members now.', $members, ['count' => $members])">
    <x-slot:actions>
        <flux:button icon="arrow-left" :href="route('workspace.groups.index', $organization)" wire:navigate>{{ __('All groups') }}</flux:button>
    </x-slot:actions>

    @unless ($group->is_active)
        <flux:callout variant="secondary" icon="lock-closed" :heading="__('This group is closed: its building is no longer managed here. Its posts stay for reading.')" data-test="group-closed" />
    @endunless

    <div class="mx-auto flex w-full max-w-2xl flex-col">
        @include('buildinggroup::partials.feed')
    </div>
</x-page>
