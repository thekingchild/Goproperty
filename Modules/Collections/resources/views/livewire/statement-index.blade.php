{{--
    Bank statements (/o/{organization}/statements; Modules\Collections\Livewire\StatementIndex).
    Rows stack into cards on phones (UX_DESIGN.md § 6); every page and action checks its policy.
--}}
<x-page :heading="__('Bank statements')" :description="__('Statements of :organization\'s bank accounts. Their credits are matched to tenants\' bills.', ['organization' => $organization->name])">
    <x-slot:actions>
        @if ($canUpload)
            <flux:button variant="primary" icon="arrow-up-tray" :href="route('workspace.statements.create', $organization)" wire:navigate data-test="upload-statement">{{ __('Upload a statement') }}</flux:button>
        @endif
        <x-list-export list="bank_statements" />
    </x-slot:actions>

    <div class="grid gap-3 sm:grid-cols-2 lg:max-w-2xl">
        <flux:select wire:model.live="account" :aria-label="__('Bank account')" size="sm" data-test="account-filter">
            <option value="">{{ __('All accounts') }}</option>
            @foreach ($accounts as $option)
                <option value="{{ $option->id }}">{{ $option->bank_name }} {{ $option->masked() }}</option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="status" :aria-label="__('Status')" size="sm" data-test="status-filter">
            <option value="">{{ __('All statuses') }}</option>
            @foreach ($statuses as $option)
                <option value="{{ $option->value }}">{{ __($option->label()) }}</option>
            @endforeach
        </flux:select>
    </div>

    @if ($statements->isEmpty())
        @if ($filtered)
            <x-empty-state icon="magnifying-glass" :heading="__('No statement matches')" :description="__('Clear the filters to see every statement.')">
                <x-slot:action>
                    <flux:button wire:click="clearFilters" data-test="clear-filters">{{ __('Clear filters') }}</flux:button>
                </x-slot:action>
            </x-empty-state>
        @else
            <x-empty-state icon="building-library" :heading="__('No statements yet')" :description="__('Upload your bank statement (CSV or Excel, as your bank exports it) to match tenants\' transfers to their bills.')" />
        @endif
    @else
        <ul @if ($reading) wire:poll.5s @endif class="divide-y divide-zinc-100 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-800 dark:border-zinc-700 dark:bg-zinc-900" data-test="statements-list">
            @foreach ($statements as $statement)
                <li class="flex flex-col gap-2 p-4 sm:flex-row sm:items-center sm:justify-between" wire:key="statement-{{ $statement->id }}" data-statement="{{ $statement->id }}">
                    <div class="flex min-w-0 flex-col">
                        <a href="{{ route('workspace.statements.show', [$organization, $statement->id]) }}" wire:navigate class="truncate font-medium text-zinc-900 hover:underline dark:text-white">{{ $statement->accountLabel() }} · {{ $statement->file_name }}</a>
                        <span class="text-sm text-zinc-500 dark:text-zinc-400">
                            @if ($statement->period_start && $statement->period_end)
                                {{ $statement->period_start->format('d/m/Y') }} – {{ $statement->period_end->format('d/m/Y') }} ·
                            @endif
                            {{ __('uploaded :date by :name', ['date' => $statement->created_at?->timezone('Africa/Lagos')->format('d/m/Y H:i'), 'name' => $statement->uploadedBy?->name ?? __('a former member')]) }}
                        </span>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300">
                        @if ($statement->status === \Modules\Collections\Enums\StatementStatus::Ready)
                            <span data-test="added-{{ $statement->id }}">{{ trans_choice(':count new line|:count new lines', $statement->lines_added, ['count' => $statement->lines_added]) }}</span>
                            @if ($statement->lines_duplicate > 0)
                                <span>· {{ trans_choice(':count duplicate skipped|:count duplicates skipped', $statement->lines_duplicate, ['count' => $statement->lines_duplicate]) }}</span>
                            @endif
                        @endif
                        <flux:badge size="sm" :color="$statement->status->color()" data-test="status-{{ $statement->id }}">{{ __($statement->status->label()) }}</flux:badge>
                    </div>
                </li>
            @endforeach
        </ul>

        <flux:pagination :paginator="$statements" />
    @endif
</x-page>
