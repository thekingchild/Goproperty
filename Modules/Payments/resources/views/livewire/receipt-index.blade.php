{{--
    Receipts (/o/{organization}/receipts; Modules\Payments\Livewire\ReceiptIndex).
    "Void requested" is the approvers' queue. Rows stack into cards on
    phones (UX_DESIGN.md § 6); every page and action checks its policy.
--}}
<x-page :heading="__('Receipts')" :description="__('Receipts :organization issued for confirmed payments.', ['organization' => $organization->name])">
    <x-slot:actions>
        <x-list-export list="receipts" />
    </x-slot:actions>

    <div class="flex flex-col gap-3">
        <div class="flex flex-wrap items-center gap-2" role="group" aria-label="{{ __('Which receipts') }}">
            <flux:button size="sm" :variant="$status === 'void_requested' ? 'primary' : 'ghost'" wire:click="$set('status', 'void_requested')" data-test="show-void-requests">
                {{ __('Voids to approve') }} <flux:badge size="sm" color="amber" class="ms-1" data-test="void-request-count">{{ $waiting }}</flux:badge>
            </flux:button>
            <flux:button size="sm" :variant="$status === '' ? 'primary' : 'ghost'" wire:click="$set('status', '')" data-test="show-all">{{ __('All receipts') }}</flux:button>
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="sm:col-span-2">
                <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" type="search" :placeholder="__('Search by receipt number or tenant')" :aria-label="__('Search receipts')" maxlength="100" data-test="receipt-search" />
            </div>
            <flux:select wire:model.live="status" :aria-label="__('Status')" size="sm" data-test="status-filter">
                <option value="">{{ __('All statuses') }}</option>
                @foreach ($statuses as $option)
                    <option value="{{ $option->value }}">{{ __($option->label()) }}</option>
                @endforeach
            </flux:select>
        </div>

        @if ($tenant !== '')
            <div class="flex flex-wrap items-center gap-2">
                <flux:text>{{ __('Showing the receipts of one tenant.') }}</flux:text>
                <flux:button size="sm" variant="ghost" wire:click="clearFilters" data-test="show-all-receipts">{{ __('Show every receipt') }}</flux:button>
            </div>
        @endif
    </div>

    @if ($receipts->isEmpty())
        @if ($filtered)
            <x-empty-state icon="magnifying-glass" :heading="$status === 'void_requested' && $search === '' ? __('No void waits for approval') : __('No receipt matches')" :description="__('Check the spelling, or clear the filters to see every receipt.')">
                <x-slot:action>
                    <flux:button wire:click="clearFilters" data-test="clear-filters">{{ __('Clear filters') }}</flux:button>
                </x-slot:action>
            </x-empty-state>
        @else
            <x-empty-state icon="document-text" :heading="__('No receipts yet')" :description="__('A numbered receipt is issued each time a payment is confirmed.')" />
        @endif
    @else
        <ul class="divide-y divide-zinc-100 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-800 dark:border-zinc-700 dark:bg-zinc-900" data-test="receipts-list">
            @foreach ($rows as $row)
                @php($receipt = $row->receipt)
                <li class="flex flex-col gap-2 p-4 sm:flex-row sm:items-center sm:justify-between" wire:key="receipt-{{ $receipt->id }}" data-receipt="{{ $receipt->id }}">
                    <div class="flex min-w-0 flex-col">
                        <a href="{{ route('workspace.receipts.show', [$organization, $receipt->id]) }}" wire:navigate class="truncate font-medium text-zinc-900 hover:underline dark:text-white">{{ $receipt->number }}</a>
                        <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ $row->tenantName }} · {{ __($row->method) }} · {{ __('issued :date', ['date' => $receipt->issued_at->timezone('Africa/Lagos')->format('d/m/Y')]) }}</span>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-medium text-zinc-900 dark:text-white" data-test="amount-{{ $receipt->id }}">{{ $receipt->amount_kobo->format() }}</span>
                        <flux:badge size="sm" :color="$receipt->status()->color()" data-test="status-{{ $receipt->id }}">{{ __($receipt->status()->label()) }}</flux:badge>
                    </div>
                </li>
            @endforeach
        </ul>

        <flux:pagination :paginator="$receipts" />
    @endif
</x-page>
