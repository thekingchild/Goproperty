{{--
    An expense (Modules\Expenses\Livewire\ExpenseShow). Receipt files are
    evidence: "remove" archives one with a reason and the file is kept.
    File links are short-lived signed links, made only for members who may
    open them (never in a GoProperty support session).
--}}
<x-page :heading="$expense->description" :description="$expense->isArchived() ? __('Archived expense of :organization.', ['organization' => $organization->name]) : __('Expense of :organization.', ['organization' => $organization->name])">
    <x-slot:actions>
        <flux:button icon="arrow-left" :href="route('workspace.expenses.index', $expense->isArchived() ? [$organization, 'archived' => 1] : $organization)" wire:navigate>{{ __('All expenses') }}</flux:button>
        @if ($canEdit)
            <flux:button icon="pencil-square" :href="route('workspace.expenses.edit', [$organization, $expense->id])" wire:navigate data-test="edit-expense">{{ __('Edit') }}</flux:button>
        @endif
        @if ($canRestore)
            <flux:button variant="primary" wire:click="restore" data-test="restore-expense">{{ __('Restore') }}</flux:button>
        @endif
    </x-slot:actions>

    @if ($notice)
        <flux:callout variant="success" icon="check-circle" :heading="$notice" role="status" data-test="expense-notice" />
    @elseif ($flash)
        <flux:callout variant="success" icon="check-circle" :heading="$flash" role="status" data-test="expense-notice" />
    @endif

    @if ($expense->isArchived())
        <flux:callout variant="warning" icon="archive-box" :heading="__('This expense is archived')" :text="__('Archived on :date by :name: :reason. It is kept with its receipts and cannot be changed until it is restored.', ['date' => $expense->deleted_at?->timezone('Africa/Lagos')->format('d/m/Y H:i'), 'name' => $people[$expense->archived_by_user_id] ?? __('someone'), 'reason' => $expense->archive_reason])" data-test="archived-banner" />
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="rounded-xl border border-zinc-200 bg-white p-5 lg:col-span-2 dark:border-zinc-700 dark:bg-zinc-900" data-test="expense-details">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <flux:heading size="lg" level="2">{{ __('The payment') }}</flux:heading>
                <span class="text-xl font-semibold tabular-nums text-zinc-900 dark:text-white" data-test="expense-amount">{{ $expense->amount_kobo->format() }}</span>
            </div>

            <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('For') }}</dt>
                    <dd class="font-medium text-zinc-900 dark:text-white" data-test="expense-place">{{ $row->buildingName === null ? __('The organization\'s own (no building)') : ($row->unitLabel === null ? $row->buildingName : $row->unitLabel.' · '.$row->buildingName) }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Category') }}</dt>
                    <dd class="text-zinc-900 dark:text-white" data-test="expense-category">{{ __($expense->category->label()) }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Date paid') }}</dt>
                    <dd class="text-zinc-900 dark:text-white">{{ $expense->spent_on->format('d/m/Y') }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('How it was paid') }}</dt>
                    <dd class="text-zinc-900 dark:text-white">{{ __($expense->payment_method->label()) }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Paid to') }}</dt>
                    <dd class="text-zinc-900 dark:text-white" data-test="expense-vendor">{{ $expense->vendor_name ?? __('Not given') }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Reference') }}</dt>
                    <dd class="break-all text-zinc-900 dark:text-white">{{ $expense->reference ?? __('Not given') }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Charged to the owner') }}</dt>
                    <dd class="text-zinc-900 dark:text-white" data-test="expense-charged">{{ $expense->charged_to_owner ? __('Yes: deducted from what the owner is due') : __('No') }}</dd>
                </div>
                @if ($expense->note)
                    <div class="sm:col-span-2">
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Note') }}</dt>
                        <dd class="whitespace-pre-line text-zinc-900 dark:text-white">{{ $expense->note }}</dd>
                    </div>
                @endif
            </dl>
        </section>

        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="expense-history">
            <flux:heading size="lg" level="2">{{ __('Record') }}</flux:heading>

            <dl class="mt-4 grid gap-4">
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Recorded') }}</dt>
                    <dd class="text-zinc-900 dark:text-white">{{ $expense->created_at?->timezone('Africa/Lagos')->format('d/m/Y H:i') }} · {{ $row->recordedBy ?? __('Unknown') }}</dd>
                </div>
                @if ($expense->updated_by_user_id !== null)
                    <div>
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Last changed') }}</dt>
                        <dd class="text-zinc-900 dark:text-white">{{ $expense->updated_at?->timezone('Africa/Lagos')->format('d/m/Y H:i') }} · {{ $people[$expense->updated_by_user_id] ?? __('Unknown') }}</dd>
                    </div>
                @endif
            </dl>

            @if ($canArchive)
                <form wire:submit="archive" class="mt-6 flex flex-col gap-3" novalidate data-test="expense-archive">
                    <flux:input wire:model="archiveReason" :label="__('Archive this expense')" :description="__('It is kept with its receipts, and can be restored. Say why.')" maxlength="500" data-test="expense-archive-reason" />
                    <div>
                        <flux:button type="submit" size="sm" variant="danger" wire:confirm="{{ __('Archive this expense? It is kept with its receipts and the reason.') }}" data-test="archive-expense">{{ __('Archive') }}</flux:button>
                    </div>
                </form>
            @endif
        </section>

        <section class="rounded-xl border border-zinc-200 bg-white p-5 lg:col-span-3 dark:border-zinc-700 dark:bg-zinc-900" data-test="expense-receipts">
            <flux:heading size="lg" level="2">{{ __('Receipts') }}</flux:heading>

            @if ($kept->isEmpty())
                <flux:text class="mt-2" data-test="no-receipts">{{ __('No receipt kept for this expense yet.') }}</flux:text>
            @else
                <ul class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($kept as $receipt)
                        @php($file = $media[$receipt->media_id] ?? null)
                        <li class="flex flex-col gap-2 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700" wire:key="receipt-{{ $receipt->id }}" data-receipt="{{ $receipt->id }}">
                            @if ($canOpenFiles && $file && str_starts_with((string) $file->mime_type, 'image/'))
                                <a href="{{ route('workspace.expenses.receipts.show', [$organization, $expense->id, $receipt->id]) }}" target="_blank" rel="noopener">
                                    <img src="{{ route('workspace.expenses.receipts.thumb', [$organization, $expense->id, $receipt->id]) }}" alt="{{ $file->name }}" class="h-32 w-full rounded object-cover" loading="lazy">
                                </a>
                            @endif
                            <span class="truncate font-medium text-zinc-900 dark:text-white">{{ $file?->name ?? __('Receipt') }}</span>
                            <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ $receipt->uploaded_at->timezone('Africa/Lagos')->format('d/m/Y H:i') }} · {{ $people[$receipt->uploaded_by_user_id] ?? __('Unknown') }}</span>
                            @if ($canOpenFiles)
                                <flux:button size="sm" icon="arrow-top-right-on-square" :href="route('workspace.expenses.receipts.show', [$organization, $expense->id, $receipt->id])" target="_blank" data-test="open-receipt-{{ $receipt->id }}">{{ __('Open') }}</flux:button>
                            @else
                                <flux:text class="text-sm" data-test="receipt-hidden-{{ $receipt->id }}">{{ __('The file is not shown in this session.') }}</flux:text>
                            @endif
                            @if ($canArchiveReceipt)
                                <form wire:submit="archiveReceipt({{ $receipt->id }})" class="flex flex-col gap-2" novalidate>
                                    <flux:input wire:model="receiptReasons.{{ $receipt->id }}" size="sm" :placeholder="__('Why remove it?')" :aria-label="__('Why remove this receipt')" maxlength="500" data-test="receipt-reason-{{ $receipt->id }}" />
                                    <div>
                                        <flux:button type="submit" size="sm" wire:confirm="{{ __('Archive this receipt? The file is kept as evidence, with the reason.') }}" data-test="archive-receipt-{{ $receipt->id }}">{{ __('Remove (archive)') }}</flux:button>
                                    </div>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif

            @error('reason')
                <flux:text class="mt-2 text-sm text-red-600 dark:text-red-400" role="alert" data-test="reason-error">{{ $message }}</flux:text>
            @enderror

            @if ($canAttach)
                <form wire:submit="addReceipts" class="mt-6 flex flex-col gap-3 sm:max-w-xl" novalidate data-test="add-receipts">
                    <flux:input type="file" wire:model="receipts" multiple accept="image/jpeg,image/png,image/webp,application/pdf" :label="__('Add receipts')" :description="__('Photos, scans or PDFs: JPEG, PNG, WebP or PDF, up to 10 MB each, :count at a time.', ['count' => $maxFiles])" data-test="receipt-files" />
                    @error('receipts.*')
                        <flux:text class="text-sm text-red-600 dark:text-red-400" role="alert">{{ $message }}</flux:text>
                    @enderror
                    <div>
                        <flux:button type="submit" size="sm" variant="primary" data-test="save-receipts">{{ __('Add') }}</flux:button>
                    </div>
                </form>
            @endif

            @if ($archivedReceipts->isNotEmpty())
                <flux:heading size="md" level="3" class="mt-6">{{ __('Archived receipts') }}</flux:heading>
                <ul class="mt-2 divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach ($archivedReceipts as $receipt)
                        @php($file = $media[$receipt->media_id] ?? null)
                        <li class="flex flex-col gap-1 py-2 sm:flex-row sm:items-center sm:justify-between" wire:key="archived-receipt-{{ $receipt->id }}" data-archived-receipt="{{ $receipt->id }}">
                            <span class="text-sm text-zinc-900 dark:text-white">{{ $file?->name ?? __('Receipt') }} · {{ __('archived :date by :name: :reason', ['date' => $receipt->archived_at?->timezone('Africa/Lagos')->format('d/m/Y H:i'), 'name' => $people[$receipt->archived_by_user_id] ?? __('someone'), 'reason' => $receipt->archive_reason]) }}</span>
                            @if ($canOpenFiles)
                                <flux:button size="sm" variant="ghost" :href="route('workspace.expenses.receipts.show', [$organization, $expense->id, $receipt->id])" target="_blank" data-test="open-receipt-{{ $receipt->id }}">{{ __('Open') }}</flux:button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
</x-page>
