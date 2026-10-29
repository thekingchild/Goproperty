{{--
    A lease's moves as a timeline in the owner portal (P03-16): moved in or
    renewed, a rent change, ended early, moved out — oldest first, each with
    its day and an optional line. $events: list<Modules\Leasing\Contracts\LeaseEvent>.
--}}
<ol class="flex flex-col gap-2 border-l-2 border-zinc-200 pl-4 dark:border-zinc-700" data-test="owner-lease-timeline">
    @foreach ($events as $event)
        <li class="flex flex-col text-sm" data-test="owner-lease-event" data-type="{{ $event->type }}">
            <span class="flex flex-wrap gap-x-2">
                <span class="font-medium text-zinc-900 dark:text-white">{{ __($event->label) }}</span>
                <span class="text-zinc-500 dark:text-zinc-400">{{ $event->on->format('d/m/Y') }}</span>
            </span>
            @if ($event->detail)
                <span class="break-words text-zinc-600 dark:text-zinc-300">{{ $event->detail }}</span>
            @endif
        </li>
    @endforeach
</ol>
