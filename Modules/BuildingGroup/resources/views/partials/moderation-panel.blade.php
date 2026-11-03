{{--
    The moderators' panel above a group's feed (P07-04; workspace only):
    the open reports by item (why, by whom, an excerpt; hide, remove,
    mute, dismiss, or go to it), the tenants muted now (unmute), and the
    group's settings. $reported (list of Feed\ReportedItem), $muted (list
    of Feed\MutedMember), $canModerate, $canRemove, $canMute, $group.
--}}
<section class="flex flex-col gap-3" data-test="moderation-panel" aria-label="{{ __('Moderation') }}">
    <div class="flex flex-col gap-3 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="report-queue">
        <flux:heading size="md" level="2">{{ __('Reports') }}</flux:heading>
        @if ($reported === [])
            <flux:text class="text-sm" data-test="no-reports">{{ __('Nothing reported. Members can report a post or comment; it shows here for you to look at.') }}</flux:text>
        @else
            <ul class="flex flex-col divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach ($reported as $item)
                    <li class="flex flex-col gap-2 py-3 first:pt-0 last:pb-0" data-test="reported-item" data-key="{{ $item->key() }}" wire:key="reported-{{ $item->key() }}">
                        <div class="flex flex-wrap items-baseline gap-x-2 text-sm">
                            <span class="font-medium text-zinc-900 dark:text-white">{{ $item->type === \Modules\BuildingGroup\Enums\ReactableType::Post ? __('Post by :name', ['name' => $item->author]) : __('Comment by :name', ['name' => $item->author]) }}</span>
                            <flux:badge size="sm" color="red">{{ trans_choice(':count report|:count reports', count($item->reports), ['count' => count($item->reports)]) }}</flux:badge>
                            @if ($item->status !== \Modules\BuildingGroup\Enums\PostStatus::Published)
                                <flux:badge size="sm" color="amber">{{ $item->status->label() }}</flux:badge>
                            @endif
                            <a href="#post-{{ $item->postId }}" class="text-zinc-600 underline dark:text-zinc-300">{{ __('Go to it') }}</a>
                        </div>
                        <flux:text class="text-sm italic" data-test="reported-excerpt">“{{ $item->excerpt }}”</flux:text>
                        <ul class="flex flex-col gap-1 text-sm text-zinc-600 dark:text-zinc-300">
                            @foreach ($item->reports as $report)
                                <li data-test="report-line">
                                    <span class="font-medium text-zinc-800 dark:text-zinc-100">{{ $report->reason->label() }}</span>
                                    {{ __('— :name, :time', ['name' => $report->reporter, 'time' => \Modules\BuildingGroup\Feed\GroupDates::show($report->at)]) }}@if ($report->note): <q>{{ $report->note }}</q>@endif
                                </li>
                            @endforeach
                        </ul>
                        @include('buildinggroup::partials.moderation-actions', ['type' => $item->type->value, 'id' => $item->id, 'status' => $item->status, 'pinned' => false, 'fromTenant' => $item->fromTenant, 'openReports' => count($item->reports), 'where' => 'queue', 'mine' => $item->mine])
                        @include('buildinggroup::partials.moderation-form', ['key' => $item->key(), 'where' => 'queue'])
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    @if ($muted !== [])
        <div class="flex flex-col gap-2 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="muted-members">
            <flux:heading size="md" level="2">{{ __('Muted tenants') }}</flux:heading>
            <ul class="flex flex-col gap-1">
                @foreach ($muted as $member)
                    <li class="flex flex-wrap items-center justify-between gap-2 text-sm" data-test="muted-member" data-member="{{ $member->memberId }}" wire:key="muted-{{ $member->memberId }}">
                        <span><span class="font-medium text-zinc-900 dark:text-white">{{ $member->name }}</span> {{ __('until :time', ['time' => \Modules\BuildingGroup\Feed\GroupDates::show($member->until)]) }}</span>
                        @if ($canMute)
                            <button type="button" wire:click="unmute({{ $member->memberId }})" class="min-h-9 rounded-md px-2 font-medium text-zinc-700 hover:bg-zinc-100 dark:text-zinc-200 dark:hover:bg-zinc-800" data-test="unmute">{{ __('Unmute') }}</button>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($canModerate)
        <details class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" data-test="group-settings">
            <summary class="cursor-pointer font-medium text-zinc-900 dark:text-white">{{ __('Group settings') }}</summary>
            <form wire:submit="saveSettings" class="mt-3 flex flex-col gap-3">
                <flux:checkbox wire:model="settingMembersPost" :label="__('Tenants can start posts')" :description="__('Off: only your team starts posts; tenants still comment and react.')" data-test="setting-members-post" />
                <flux:checkbox wire:model="settingImages" :label="__('Posts can have photos')" data-test="setting-images" />
                <div>
                    <flux:button type="submit" size="sm" variant="primary" class="min-h-11" data-test="save-settings">{{ __('Save settings') }}</flux:button>
                </div>
            </form>
        </details>
    @endif
</section>
