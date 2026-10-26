{{-- Empty state with a clear next step (UX_DESIGN.md § 6). --}}
@props(['icon' => 'inbox', 'heading', 'description' => null])

<div {{ $attributes->merge(['class' => 'flex flex-col items-center gap-3 rounded-xl border border-dashed border-zinc-300 px-6 py-12 text-center dark:border-zinc-700']) }}>
    <span class="flex size-12 items-center justify-center rounded-full bg-primary-50 text-primary-700 dark:bg-primary-950 dark:text-primary-300">
        <flux:icon :name="$icon" class="size-6" />
    </span>
    <flux:heading size="lg" level="2">{{ $heading }}</flux:heading>
    @if ($description)
        <flux:text class="max-w-md">{{ $description }}</flux:text>
    @endif
    @isset($action)
        <div class="mt-2">{{ $action }}</div>
    @endisset
</div>
