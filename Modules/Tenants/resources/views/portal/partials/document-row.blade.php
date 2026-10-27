{{--
    One document in the tenant portal's documents list (P03-11b): its title
    and a line of detail; the whole row downloads it ($url), or — in a
    GoProperty support session ($url null) — says it does not open here.
--}}
<li data-test="{{ $test }}" data-document="{{ $id }}">
    @if ($url)
        <a href="{{ $url }}" class="flex min-h-11 items-center justify-between gap-3 rounded-xl border border-zinc-200 bg-white px-4 py-3 hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-900">
            <span class="min-w-0">
                <span class="block font-medium break-all text-zinc-900 dark:text-white">{{ $title }}</span>
                <span class="block text-sm break-words text-zinc-600 dark:text-zinc-300">{{ $detail }}</span>
            </span>
            <flux:icon name="arrow-down-tray" class="size-5 shrink-0 text-zinc-500" />
            <span class="sr-only">{{ __('Download (PDF)') }}</span>
        </a>
    @else
        <div class="flex min-h-11 flex-col justify-center rounded-xl border border-dashed border-zinc-200 px-4 py-3 dark:border-zinc-700">
            <span class="block font-medium break-all text-zinc-900 dark:text-white">{{ $title }}</span>
            <span class="block text-sm break-words text-zinc-600 dark:text-zinc-300">{{ $detail }}</span>
        </div>
    @endif
</li>
