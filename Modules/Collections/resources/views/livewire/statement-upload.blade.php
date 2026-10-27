{{--
    Upload a bank statement (/o/{organization}/statements/upload; Modules\Collections\Livewire\StatementUpload).
    Step 1: account and file. Step 2: which column holds what, then Import. Nothing is stored before Import.
--}}
<x-page :heading="__('Upload a bank statement')" :description="__('Upload the statement as your bank exports it (CSV or Excel). Only credits are matched to tenants\' bills; debits are kept for the record.')">
    <x-slot:actions>
        <flux:button icon="arrow-left" :href="route('workspace.statements.index', $organization)" wire:navigate>{{ __('All statements') }}</flux:button>
    </x-slot:actions>

    @if ($accounts->isEmpty())
        <x-empty-state icon="building-library" :heading="__('No bank account yet')" :description="__('The organization\'s owner adds its bank accounts in Settings → Payment collection. Statements are uploaded for one of them.')" />
    @else
        <form wire:submit="read" class="flex max-w-2xl flex-col gap-5 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:select wire:model.live="accountId" :label="__('Bank account')" data-test="statement-account">
                @foreach ($accounts as $option)
                    <option value="{{ $option->id }}">{{ $option->bank_name }} {{ $option->masked() }} · {{ $option->account_name }}@unless ($option->active) ({{ __('deactivated') }})@endunless</option>
                @endforeach
            </flux:select>
            <flux:error name="accountId" />

            <div class="flex flex-col gap-2">
                <flux:label for="statement-file">{{ __('Statement file') }}</flux:label>
                <input id="statement-file" type="file" wire:model="file" accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" class="block text-sm text-zinc-700 file:me-3 file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:py-2 file:text-sm file:font-medium dark:text-zinc-300 dark:file:bg-zinc-800" data-test="statement-file">
                <flux:text class="text-sm">{{ __('Excel (.xlsx) or CSV, up to 10 MB. Older .xls files: open them in Excel and save as .xlsx first.') }}</flux:text>
                <div wire:loading wire:target="file" class="text-sm text-zinc-500">{{ __('Uploading…') }}</div>
                <flux:error name="file" />
            </div>

            @unless ($mapping)
                <div>
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled" data-test="read-statement">{{ __('Check the columns') }}</flux:button>
                </div>
            @endunless
        </form>

        @if ($mapping)
            <form wire:submit="import" class="flex flex-col gap-5 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="statement-mapping">
                <div>
                    <flux:heading size="lg" level="2">{{ __('Which column holds what') }}</flux:heading>
                    <flux:text class="mt-1">
                        {{ $savedMapping ? __('Filled in from this account\'s last statement. Check it, then import.') : __('Worked out from the column names. Check it, then import; it is remembered for this account.') }}
                    </flux:text>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach ($fields as $field)
                        <div>
                            <flux:select wire:model="columns.{{ $field->value }}" :label="__($field->label())" data-test="column-{{ $field->value }}">
                                <option value="">{{ $field->required() ? __('Choose a column') : __('Not in this statement') }}</option>
                                @foreach ($headings as $position => $heading)
                                    <option value="{{ $position }}">{{ $heading }}</option>
                                @endforeach
                            </flux:select>
                            <flux:error name="columns.{{ $field->value }}" />
                        </div>
                    @endforeach

                    <div>
                        <flux:select wire:model="dateOrder" :label="__('Dates are written')" data-test="date-order">
                            @foreach ($dateOrders as $order)
                                <option value="{{ $order->value }}">{{ __($order->label()) }}</option>
                            @endforeach
                        </flux:select>
                        <flux:error name="dateOrder" />
                    </div>
                </div>

                @if ($sample !== [])
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-left text-sm" data-test="statement-sample">
                            <caption class="mb-2 text-start text-sm text-zinc-500 dark:text-zinc-400">{{ __('The first rows of the statement') }}</caption>
                            <thead>
                                <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                    @foreach ($headings as $heading)
                                        <th scope="col" class="px-2 py-1 font-medium text-zinc-700 dark:text-zinc-200">{{ $heading }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($sample as $cells)
                                    <tr class="border-b border-zinc-100 dark:border-zinc-800">
                                        @foreach (array_keys($headings) as $position)
                                            <td class="px-2 py-1 text-zinc-600 dark:text-zinc-300">{{ $cells[$position] ?? '' }}</td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                <div class="flex flex-wrap gap-2">
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled" data-test="import-statement">{{ __('Import the statement') }}</flux:button>
                </div>
            </form>
        @endif
    @endif
</x-page>
