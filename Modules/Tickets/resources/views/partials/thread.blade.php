{{--
    A ticket's thread, oldest first (Messages\TicketThread; P04-02): each
    message with who wrote it (as which party), when (Lagos), the words and
    the files. $messages: list<ThreadMessage> (internal notes only ever on
    the staff page: the query leaves them out for the tenant and the
    owner); $fileUrl: fn (int $mediaId, bool $thumb): ?string, null while
    files cannot be opened (GoProperty support session).
--}}
@php
    use Modules\Tickets\Tickets\TicketDates;
@endphp
<ol class="flex flex-col gap-3" data-test="ticket-thread">
    @foreach ($messages as $message)
        <li @class([
                'flex min-w-0 flex-col gap-2 rounded-xl border p-3',
                'border-amber-300 bg-amber-50 dark:border-amber-700 dark:bg-amber-950/40' => $message->internal,
                'border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800/50' => ! $message->internal && $message->mine,
                'border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900' => ! $message->internal && ! $message->mine,
            ]) data-test="thread-message" data-message="{{ $message->id }}" data-internal="{{ $message->internal ? '1' : '0' }}" data-author="{{ $message->author->value }}">
            <span class="flex flex-wrap items-center gap-2 text-sm">
                <span class="font-medium break-words text-zinc-900 dark:text-white">{{ $message->authorName }}</span>
                <flux:badge size="sm" :color="match ($message->author) { \Modules\Tickets\Enums\MessageAuthor::Tenant => 'sky', \Modules\Tickets\Enums\MessageAuthor::Owner => 'violet', default => 'zinc' }">{{ __($message->author->label()) }}</flux:badge>
                @if ($message->internal)
                    <flux:badge size="sm" color="amber" icon="lock-closed" data-test="internal-badge">{{ __('Internal note: staff only') }}</flux:badge>
                @endif
                <span class="text-zinc-500 dark:text-zinc-400">{{ TicketDates::show($message->postedAt) }}</span>
            </span>

            @if ($message->body !== null)
                <p class="break-words whitespace-pre-line text-zinc-800 dark:text-zinc-200" data-test="message-text">{{ $message->body }}</p>
            @endif

            @if ($message->attachments !== [])
                <ul class="flex flex-wrap gap-2" data-test="message-files">
                    @foreach ($message->attachments as $file)
                        @php($url = $fileUrl($file->mediaId, false))
                        <li class="min-w-0 max-w-full" data-test="message-file" data-media="{{ $file->mediaId }}">
                            @if ($url === null)
                                <span class="flex min-h-11 max-w-full items-center gap-2 rounded-lg border border-zinc-200 bg-white px-3 text-sm text-zinc-600 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300">
                                    <flux:icon.paper-clip class="size-4 shrink-0" />
                                    <span class="truncate">{{ $file->name }}</span>
                                </span>
                            @elseif ($file->picture)
                                <a href="{{ $url }}" target="_blank" rel="noopener" class="block size-20 overflow-hidden rounded-lg border border-zinc-200 bg-zinc-100 dark:border-zinc-700 dark:bg-zinc-800" title="{{ $file->name }}">
                                    <img src="{{ $fileUrl($file->mediaId, true) }}" alt="{{ $file->name }}" loading="lazy" class="size-full object-cover">
                                </a>
                            @else
                                <a href="{{ $url }}" target="_blank" rel="noopener" class="flex min-h-11 max-w-full items-center gap-2 rounded-lg border border-zinc-200 bg-white px-3 text-sm text-zinc-800 hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100">
                                    <flux:icon.paper-clip class="size-4 shrink-0" />
                                    <span class="truncate">{{ $file->name }}</span>
                                    <span class="shrink-0 text-zinc-500 dark:text-zinc-400">{{ $file->caption() }}</span>
                                </a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </li>
    @endforeach
</ol>
