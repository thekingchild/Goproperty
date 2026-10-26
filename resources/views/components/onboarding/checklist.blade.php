{{--
    Setup checklist on the workspace dashboard (ONBOARDING_COMPLIANCE.md § 2
    step 7): tier, plan, collections, data and branding, from the
    organization's `onboarding_checklist_items`. Shown until every item is
    done; an item links to its page once that feature exists
    (ChecklistItem::routeName()).
--}}
@props(['items', 'organization'])
@php
    $done = $items->filter(fn ($row) => $row->isDone())->count();
    $total = $items->count();
@endphp
<section {{ $attributes->merge(['class' => 'rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900']) }} aria-labelledby="setup-checklist-heading" data-test="setup-checklist">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <flux:heading size="lg" level="2" id="setup-checklist-heading">{{ __('Finish setting up') }}</flux:heading>
        <flux:text>{{ __(':done of :total done', ['done' => $done, 'total' => $total]) }}</flux:text>
    </div>
    <div class="mt-3 h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800" role="progressbar" aria-label="{{ __('Setup progress') }}" aria-valuemin="0" aria-valuemax="{{ $total }}" aria-valuenow="{{ $done }}">
        <div class="h-full rounded-full bg-primary-600" style="width: {{ $total > 0 ? intdiv($done * 100, $total) : 0 }}%"></div>
    </div>

    <ul class="mt-4 divide-y divide-zinc-100 dark:divide-zinc-800">
        @foreach ($items as $row)
            @php
                $route = $row->item->routeName();
                $href = ! $row->isDone() && $route !== null && \Illuminate\Support\Facades\Route::has($route) ? route($route, $organization) : null;
                $requestedTier = $row->item === \App\Enums\ChecklistItem::Tier ? ($row->details['requested_tier'] ?? null) : null;
            @endphp
            <li class="flex items-start gap-3 py-3" data-item="{{ $row->item->value }}" data-status="{{ $row->status->value }}">
                @if ($row->isDone())
                    <span class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-300">
                        <flux:icon name="check" variant="micro" />
                        <span class="sr-only">{{ __('Done:') }}</span>
                    </span>
                @else
                    <span class="mt-0.5 size-6 shrink-0 rounded-full border-2 border-zinc-300 dark:border-zinc-600"><span class="sr-only">{{ __('To do:') }}</span></span>
                @endif
                <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                    <span @class(['font-medium', 'text-zinc-500 line-through dark:text-zinc-400' => $row->isDone(), 'text-zinc-900 dark:text-white' => ! $row->isDone()])>{{ $row->item->label() }}</span>
                    @if (! $row->isDone())
                        <flux:text>
                            @if (is_int($requestedTier) && $requestedTier > 1)
                                {{ __('Tier :tier requested: upload your documents for the compliance review. You work at Tier 1 limits until it is approved.', ['tier' => $requestedTier]) }}
                            @else
                                {{ __($row->item->description()) }}
                            @endif
                        </flux:text>
                    @endif
                </div>
                @if ($href !== null)
                    <flux:button size="sm" :href="$href">{{ __('Start') }}</flux:button>
                @elseif (! $row->isDone())
                    <flux:badge size="sm">{{ __('Coming soon') }}</flux:badge>
                @endif
            </li>
        @endforeach
    </ul>
</section>
