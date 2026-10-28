{{--
    The tenant portal's tabs (App\Navigation\PortalTabs: each module adds
    its pages; P03-11a, P03-11b, P04-01): big targets (44 px), two a row at
    360 px.
--}}
@php($tabs = app(\App\Navigation\PortalTabs::class)->all())
<nav class="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap" aria-label="{{ __('My rentals') }}" data-test="portal-nav">
    @foreach ($tabs as $tab)
        @php($active = request()->routeIs(...$tab->active))
        <a href="{{ route($tab->route) }}" wire:navigate @if ($active) aria-current="page" @endif data-tab="{{ $tab->key }}"
            @class([
                'inline-flex min-h-11 items-center justify-center gap-2 rounded-full border px-4 text-sm font-medium sm:justify-start',
                'border-primary-600 bg-primary-50 text-primary-800 dark:border-primary-400 dark:bg-primary-950 dark:text-primary-200' => $active,
                'border-zinc-200 bg-white text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200' => ! $active,
            ])>
            <flux:icon :name="$tab->icon" class="size-5" />
            {{ __($tab->label) }}
        </a>
    @endforeach
</nav>
