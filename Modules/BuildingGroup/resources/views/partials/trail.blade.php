{{--
    A post's or comment's moderation trail, for moderators (P07-04): who
    hid, showed again, removed, pinned or unpinned it, when and why.
    $trail (list of Feed\TrailEntry).
--}}
<ol class="flex flex-col gap-1 text-sm text-zinc-600 dark:text-zinc-300" data-test="moderation-trail">
    @foreach ($trail as $entry)
        <li data-action="{{ $entry->action->value }}">
            <span class="font-medium text-zinc-800 dark:text-zinc-100">{{ $entry->action->label() }}</span>
            {{ __('by :name, :time', ['name' => $entry->actor, 'time' => \Modules\BuildingGroup\Feed\GroupDates::show($entry->at)]) }}@if ($entry->reason) — <q data-test="moderation-reason">{{ $entry->reason }}</q>@endif
        </li>
    @endforeach
</ol>
