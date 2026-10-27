{{-- The tenant portal's own tabs (P03-11a): big targets (44 px), wrap at 360 px. --}}
@php
    $tabs = [
        ['label' => __('Home'), 'icon' => 'home-modern', 'url' => route('portal.home'), 'active' => request()->routeIs('portal.home', 'portal.leases.*')],
        ['label' => __('My bills'), 'icon' => 'banknotes', 'url' => route('portal.bills.index'), 'active' => request()->routeIs('portal.bills.*')],
    ];
@endphp
<nav class="flex flex-wrap gap-2" aria-label="{{ __('My rentals') }}" data-test="portal-nav">
    @foreach ($tabs as $tab)
        <a href="{{ $tab['url'] }}" wire:navigate @if ($tab['active']) aria-current="page" @endif
            @class([
                'inline-flex min-h-11 items-center gap-2 rounded-full border px-4 text-sm font-medium',
                'border-primary-600 bg-primary-50 text-primary-800 dark:border-primary-400 dark:bg-primary-950 dark:text-primary-200' => $tab['active'],
                'border-zinc-200 bg-white text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200' => ! $tab['active'],
            ])>
            <flux:icon :name="$tab['icon']" class="size-5" />
            {{ $tab['label'] }}
        </a>
    @endforeach
</nav>
