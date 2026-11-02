{{--
    The reactions of a post or comment (P07-02): the chosen emojis with
    their counts (the viewer's own marked), and, when the viewer may react,
    the curated set to choose from; the same one again takes it back.
    $type (`group_post`, `group_comment`), $id, $reactions (list of
    Feed\ReactionCount), $canReact.
--}}
<div class="flex flex-wrap items-center gap-1" data-test="reactions" data-type="{{ $type }}" data-id="{{ $id }}">
    @foreach ($reactions as $count)
        <button type="button" @if ($canReact) wire:click="react('{{ $type }}', {{ $id }}, '{{ $count->reaction->value }}')" @else disabled @endif
            @class([
                'inline-flex min-h-9 items-center gap-1 rounded-full border px-2 text-sm',
                'border-primary-500 bg-primary-50 text-primary-800 dark:border-primary-400 dark:bg-primary-950 dark:text-primary-200' => $count->mine,
                'border-zinc-200 bg-white text-zinc-700 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200' => ! $count->mine,
            ])
            aria-pressed="{{ $count->mine ? 'true' : 'false' }}" aria-label="{{ __(':reaction: :count', ['reaction' => __($count->reaction->label()), 'count' => $count->count]) }}"
            data-reaction="{{ $count->reaction->value }}" data-count="{{ $count->count }}">
            <span aria-hidden="true">{{ $count->reaction->symbol() }}</span><span class="tabular-nums">{{ $count->count }}</span>
        </button>
    @endforeach
    @if ($canReact)
        <div x-data="{ open: false }" class="relative">
            <button type="button" x-on:click="open = ! open" class="inline-flex min-h-9 items-center gap-1 rounded-full px-2 text-sm text-zinc-500 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800" aria-label="{{ __('React') }}" data-test="react-open">
                <flux:icon name="face-smile" class="size-5" />
            </button>
            <div x-show="open" x-cloak x-on:click.outside="open = false" class="absolute z-10 mt-1 flex gap-1 rounded-full border border-zinc-200 bg-white p-1 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                @foreach (\Modules\BuildingGroup\Enums\Reaction::cases() as $reaction)
                    <button type="button" x-on:click="open = false" wire:click="react('{{ $type }}', {{ $id }}, '{{ $reaction->value }}')"
                        class="inline-flex size-10 items-center justify-center rounded-full text-xl hover:bg-zinc-100 dark:hover:bg-zinc-800"
                        title="{{ __($reaction->label()) }}" aria-label="{{ __($reaction->label()) }}" data-choose="{{ $reaction->value }}">{{ $reaction->symbol() }}</button>
                @endforeach
            </div>
        </div>
    @endif
</div>
