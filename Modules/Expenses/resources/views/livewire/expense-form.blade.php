{{--
    Record or change an expense (Modules\Expenses\Livewire\ExpenseForm).
    Amounts are typed in naira; the server keeps them as kobo, checks the
    receipt files (type sniffed from their bytes, size cap) and everything
    else again.
--}}
<x-page :heading="$expense ? __('Change the expense') : __('Record an expense')" :description="__('Money :organization paid for a building, a unit or its own running costs.', ['organization' => $organization->name])">
    <x-slot:actions>
        @if ($expense)
            <flux:button icon="arrow-left" :href="route('workspace.expenses.show', [$organization, $expense->id])" wire:navigate>{{ __('Back to the expense') }}</flux:button>
        @else
            <flux:button icon="arrow-left" :href="route('workspace.expenses.index', $organization)" wire:navigate>{{ __('All expenses') }}</flux:button>
        @endif
    </x-slot:actions>

    <form wire:submit="save" class="flex flex-col gap-6" novalidate>
        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="lg" level="2">{{ __('What it was for') }}</flux:heading>

            <div class="mt-4 grid gap-5 sm:grid-cols-2">
                <flux:select wire:model.live="propertyId" :label="__('Building')" data-test="expense-building">
                    @if ($canUnlinked)
                        <option value="">{{ __('No building (the organization\'s own)') }}</option>
                    @else
                        <option value="">{{ __('Choose a building') }}</option>
                    @endif
                    @foreach ($buildings as $optionId => $optionName)
                        <option value="{{ $optionId }}">{{ $optionName }}</option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="unitId" :label="__('Unit (optional)')" :disabled="$units === []" data-test="expense-unit">
                    <option value="">{{ __('The whole building') }}</option>
                    @foreach ($units as $optionId => $optionLabel)
                        <option value="{{ $optionId }}">{{ $optionLabel }}</option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="category" :label="__('Category')" required data-test="expense-category">
                    <option value="">{{ __('Choose a category') }}</option>
                    @foreach ($categories as $option)
                        <option value="{{ $option->value }}">{{ __($option->label()) }} — {{ __($option->hint()) }}</option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="description" :label="__('What was paid for')" :placeholder="__('Fixed the leaking pipe in Flat 2')" maxlength="500" required data-test="expense-description" />
            </div>
        </section>

        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="lg" level="2">{{ __('The payment') }}</flux:heading>

            <div class="mt-4 grid gap-5 sm:grid-cols-2">
                <flux:input wire:model="amount" inputmode="decimal" :label="__('Amount (₦)')" placeholder="25,000" maxlength="20" required data-test="expense-amount" />
                <flux:input wire:model="spentOn" type="date" :label="__('Date paid')" required data-test="expense-spent-on" />

                <flux:input wire:model="vendorName" :label="__('Paid to (optional)')" :description="__('The plumber, the diesel supplier, the council.')" maxlength="150" data-test="expense-vendor" />
                <flux:select wire:model="paymentMethod" :label="__('How it was paid')" required data-test="expense-method">
                    @foreach ($methods as $option)
                        <option value="{{ $option->value }}">{{ __($option->label()) }}</option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="reference" :label="__('Reference (optional)')" :description="__('A transfer reference, a cheque or invoice number.')" maxlength="100" data-test="expense-reference" />
                <flux:textarea wire:model="note" :label="__('Note (optional)')" rows="2" maxlength="1000" data-test="expense-note" />

                <div class="sm:col-span-2">
                    <flux:checkbox wire:model="chargedToOwner" :label="__('Charge to the building\'s owner')" :description="__('Deducted from what the owner is due on their monthly statement.')" data-test="expense-charged-to-owner" />
                    @error('chargedToOwner')
                        <flux:text class="mt-1 text-sm text-red-600 dark:text-red-400" role="alert" data-test="expense-charged-error">{{ $message }}</flux:text>
                    @enderror
                </div>

                @if ($canAttach)
                    <div class="sm:col-span-2">
                        <flux:input type="file" wire:model="receipts" multiple accept="image/jpeg,image/png,image/webp,application/pdf" :label="__('Receipts (optional)')" :description="__('Photos, scans or PDFs of the receipt or invoice: JPEG, PNG, WebP or PDF, up to 10 MB each, :count at a time. Kept as evidence.', ['count' => $maxFiles])" data-test="expense-receipts" />
                        @error('receipts.*')
                            <flux:text class="mt-1 text-sm text-red-600 dark:text-red-400" role="alert">{{ $message }}</flux:text>
                        @enderror
                    </div>
                @endif
            </div>
        </section>

        <div class="flex flex-wrap gap-2">
            <flux:button type="submit" variant="primary" data-test="save-expense">{{ $expense ? __('Save changes') : __('Record the expense') }}</flux:button>
        </div>
    </form>
</x-page>
