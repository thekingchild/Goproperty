{{--
    Expenses (/o/{organization}/expenses; Modules\Expenses\Livewire\ExpenseIndex).
    Rows stack into cards on phones (UX_DESIGN.md § 6). Buttons show only
    for what the member may do; every page and action checks its policy again.
--}}
<x-page :heading="__('Expenses')" :description="__('What :organization spent on its buildings and its own running costs, with the receipts.', ['organization' => $organization->name])">
    <x-slot:actions>
        <x-list-export list="expenses" />
        @if ($canCreate)
            <flux:button variant="primary" icon="plus" :href="route('workspace.expenses.create', $organization)" wire:navigate data-test="add-expense">{{ __('Record an expense') }}</flux:button>
        @endif
    </x-slot:actions>

    <div class="flex flex-col gap-3">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="w-full sm:max-w-sm">
                <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" type="search" :placeholder="__('Search by description, payee or reference')" :aria-label="__('Search expenses')" maxlength="100" data-test="expense-search" />
            </div>
            <div class="flex gap-1" role="group" aria-label="{{ __('Show') }}">
                <flux:button size="sm" :variant="$archived ? 'ghost' : 'filled'" wire:click="$set('archived', false)" :aria-pressed="$archived ? 'false' : 'true'">{{ __('Active') }}</flux:button>
                <flux:button size="sm" :variant="$archived ? 'filled' : 'ghost'" wire:click="$set('archived', true)" :aria-pressed="$archived ? 'true' : 'false'" data-test="show-archived">{{ __('Archived') }}</flux:button>
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <flux:select wire:model.live="category" :aria-label="__('Category')" size="sm" data-test="category-filter">
                <option value="">{{ __('All categories') }}</option>
                @foreach ($categories as $option)
                    <option value="{{ $option->value }}">{{ __($option->label()) }}</option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="building" :aria-label="__('Building')" size="sm" data-test="building-filter">
                <option value="">{{ __('All buildings') }}</option>
                @if ($seesOrganization)
                    <option value="{{ \Modules\Expenses\Lists\ExpenseList::NO_BUILDING }}">{{ __('No building (the organization\'s own)') }}</option>
                @endif
                @foreach ($buildings as $buildingId => $buildingName)
                    <option value="{{ $buildingId }}">{{ $buildingName }}</option>
                @endforeach
            </flux:select>
            <flux:input wire:model.live="from" type="date" size="sm" :aria-label="__('Paid from')" data-test="from-filter" />
            <flux:input wire:model.live="to" type="date" size="sm" :aria-label="__('Paid until')" data-test="to-filter" />
        </div>
    </div>

    @if ($expenses->isEmpty())
        @if ($filtered)
            <x-empty-state icon="magnifying-glass" :heading="__('No expense matches')" :description="__('Check the spelling, or clear the filters to see every expense.')">
                <x-slot:action>
                    <flux:button wire:click="clearFilters" data-test="clear-filters">{{ __('Clear filters') }}</flux:button>
                </x-slot:action>
            </x-empty-state>
        @elseif ($archived)
            <x-empty-state icon="archive-box" :heading="__('No archived expenses')" :description="__('Expenses you archive are kept here with their receipts.')" />
        @else
            <x-empty-state icon="wallet" :heading="__('No expenses yet')" :description="__('Record what you pay for repairs, diesel, security, rates and running the office, and keep the receipts with each one.')">
                @if ($canCreate)
                    <x-slot:action>
                        <flux:button variant="primary" icon="plus" :href="route('workspace.expenses.create', $organization)" wire:navigate>{{ __('Record an expense') }}</flux:button>
                    </x-slot:action>
                @endif
            </x-empty-state>
        @endif
    @else
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <flux:text>{{ trans_choice(':count expense|:count expenses', $expenses->total(), ['count' => number_format($expenses->total())]) }}</flux:text>
            <flux:text class="font-medium text-zinc-900 dark:text-white" data-test="expenses-total">{{ __('Total: :amount', ['amount' => $total->format()]) }}</flux:text>
        </div>

        <ul class="divide-y divide-zinc-100 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-800 dark:border-zinc-700 dark:bg-zinc-900" data-test="expenses-list">
            @foreach ($rows as $row)
                @php($expense = $row->expense)
                <li class="flex flex-col gap-2 p-4 sm:flex-row sm:items-center sm:justify-between" wire:key="expense-{{ $expense->id }}" data-expense="{{ $expense->id }}">
                    <div class="flex min-w-0 flex-col">
                        <a href="{{ route('workspace.expenses.show', [$organization, $expense->id]) }}" wire:navigate class="truncate font-medium text-zinc-900 hover:underline dark:text-white">{{ $expense->description }}</a>
                        <span class="truncate text-sm text-zinc-500 dark:text-zinc-400">
                            {{ $row->buildingName === null ? __('The organization\'s own') : ($row->unitLabel === null ? $row->buildingName : $row->unitLabel.' · '.$row->buildingName) }}@if ($expense->vendor_name) · {{ __('paid to :payee', ['payee' => $expense->vendor_name]) }}@endif
                        </span>
                        <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ $expense->spent_on->format('d/m/Y') }} · {{ __($expense->category->label()) }} · {{ __($expense->payment_method->label()) }}</span>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        @if ($expense->charged_to_owner)
                            <flux:badge size="sm" color="indigo" data-test="charged-{{ $expense->id }}">{{ __('Charged to owner') }}</flux:badge>
                        @endif
                        @if ($row->receipts === 0)
                            <flux:badge size="sm" color="amber" data-test="no-receipt-{{ $expense->id }}">{{ __('No receipt') }}</flux:badge>
                        @endif
                        <span class="font-medium tabular-nums text-zinc-900 dark:text-white" data-test="amount-{{ $expense->id }}">{{ $expense->amount_kobo->format() }}</span>
                    </div>
                </li>
            @endforeach
        </ul>

        <flux:pagination :paginator="$expenses" />
    @endif
</x-page>
