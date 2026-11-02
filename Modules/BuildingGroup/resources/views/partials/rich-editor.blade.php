{{--
    The rich-text box of a post, comment or reply (P07-02; ARCHITECTURE.md
    § 6.8 "limited toolbar: bold, italic, lists, links"): Alpine's
    `richEditor` (resources/js/app.js) entangled with the Livewire property
    $model. The server cleans whatever arrives (Posts\RichText). $id, $label,
    $placeholder; $compact for comments. 44 px targets; no autofocus.
--}}
@php($compact = $compact ?? false)
<div class="flex flex-col gap-1" x-data="richEditor($wire.entangle('{{ $model }}'))" data-test="rich-editor" data-model="{{ $model }}">
    <span id="{{ $id }}-label" class="text-sm font-medium text-zinc-800 dark:text-white">{{ $label }}</span>
    <div class="rounded-lg border border-zinc-200 bg-white focus-within:border-primary-500 dark:border-zinc-700 dark:bg-zinc-900" wire:ignore>
        <div class="flex flex-wrap gap-1 border-b border-zinc-100 p-1 dark:border-zinc-800" role="toolbar" aria-label="{{ __('Formatting') }}">
            @foreach ([['bold', 'bold', __('Bold')], ['italic', 'italic', __('Italic')], ['insertUnorderedList', 'list-bullet', __('Bulleted list')], ['insertOrderedList', 'numbered-list', __('Numbered list')]] as [$command, $icon, $title])
                <button type="button" x-on:mousedown.prevent x-on:click="run('{{ $command }}')" title="{{ $title }}" aria-label="{{ $title }}"
                    class="inline-flex size-11 items-center justify-center rounded-md text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800">
                    <flux:icon :name="$icon" class="size-5" />
                </button>
            @endforeach
            <button type="button" x-on:mousedown.prevent x-on:click="link(@js(__('Link address (https://…)')))" title="{{ __('Link') }}" aria-label="{{ __('Link') }}"
                class="inline-flex size-11 items-center justify-center rounded-md text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800">
                <flux:icon name="link" class="size-5" />
            </button>
        </div>
        <div x-ref="area" contenteditable="true" role="textbox" aria-multiline="true" aria-labelledby="{{ $id }}-label" id="{{ $id }}"
            data-placeholder="{{ $placeholder }}" x-on:input="sync()" x-on:blur="sync()" x-on:paste="paste($event)"
            @class([
                'group-rich-text w-full max-w-full overflow-x-auto px-3 py-2 text-base text-zinc-900 outline-none dark:text-zinc-100 [&_a]:text-primary-700 [&_a]:underline dark:[&_a]:text-primary-300 [&_ol]:list-decimal [&_ol]:ps-6 [&_ul]:list-disc [&_ul]:ps-6',
                'empty:before:text-zinc-400 empty:before:content-[attr(data-placeholder)]',
                'min-h-16' => $compact,
                'min-h-28' => ! $compact,
            ])></div>
    </div>
</div>
