{{--
    Page template for every signed-in surface (UX_DESIGN.md § 6): heading,
    one line of context, actions on the right (stacked on mobile), content.
--}}
@props(['heading', 'description' => null])

<div {{ $attributes->merge(['class' => 'mx-auto flex w-full max-w-6xl flex-col gap-6']) }}>
    <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div class="flex flex-col gap-1">
            <flux:heading size="xl" level="1">{{ $heading }}</flux:heading>
            @if ($description)
                <flux:text>{{ $description }}</flux:text>
            @endif
        </div>

        @isset($actions)
            <div class="flex flex-wrap gap-2">{{ $actions }}</div>
        @endisset
    </header>

    {{ $slot }}
</div>
