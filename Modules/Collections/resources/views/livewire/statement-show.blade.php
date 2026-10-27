{{--
    A bank statement (Modules\Collections\Livewire\StatementShow): the import report, matching, then the lines.
    Credits still to match are the candidates for matching (P03-07b); debits are never offered.
    Matching actions show only with statements.match; the server checks again.
--}}
<x-page :heading="$statement->accountLabel()" :description="$statement->file_name">
    <x-slot:actions>
        <flux:button icon="arrow-left" :href="route('workspace.statements.index', $organization)" wire:navigate>{{ __('All statements') }}</flux:button>
        @if ($canDownload)
            <flux:button icon="arrow-down-tray" :href="route('workspace.statements.file', [$organization, $statement->id])" data-test="download-statement">{{ __('Uploaded file') }}</flux:button>
        @endif
        <x-list-export list="bank_statement_lines" />
    </x-slot:actions>

    @if (session('statement-status'))
        <flux:callout variant="success" icon="check-circle" :heading="session('statement-status')" role="status" data-test="statement-flash" />
    @endif

    @if ($notice)
        <flux:callout variant="success" icon="check-circle" :heading="$notice" role="status" data-test="matching-notice" />
    @endif

    <section @if ($reading) wire:poll.5s @endif class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="statement-report">
        <div class="flex flex-wrap items-center gap-2">
            <flux:heading size="lg" level="2">{{ __('Import report') }}</flux:heading>
            <flux:badge size="sm" :color="$statement->status->color()" data-test="statement-status">{{ __($statement->status->label()) }}</flux:badge>
        </div>

        @if ($reading)
            <flux:text class="mt-2">{{ __('The statement is being read. This page updates by itself.') }}</flux:text>
        @elseif ($statement->status === \Modules\Collections\Enums\StatementStatus::Failed)
            <flux:callout class="mt-3" variant="danger" icon="x-circle" :heading="$statement->failure_reason ?? __('The statement could not be read.')" data-test="statement-failure" />
        @else
            <dl class="mt-4 grid gap-4 sm:grid-cols-3">
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Period') }}</dt>
                    <dd class="text-zinc-900 dark:text-white" data-test="statement-period">{{ $statement->period_start?->format('d/m/Y') ?? '—' }} – {{ $statement->period_end?->format('d/m/Y') ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Transactions read') }}</dt>
                    <dd class="text-zinc-900 dark:text-white" data-test="report-total">{{ $statement->lines_total }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('New lines added') }}</dt>
                    <dd class="text-zinc-900 dark:text-white" data-test="report-added">{{ $statement->lines_added }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Credits to match') }}</dt>
                    <dd class="text-zinc-900 dark:text-white" data-test="report-credits">{{ $statement->lines_credit }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Debits (kept, never matched)') }}</dt>
                    <dd class="text-zinc-900 dark:text-white" data-test="report-debits">{{ $statement->lines_debit }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Duplicates skipped') }}</dt>
                    <dd class="text-zinc-900 dark:text-white" data-test="report-duplicates">{{ $statement->lines_duplicate }}</dd>
                </div>
            </dl>

            @if ($statement->lines_unreadable > 0)
                <div class="mt-4" data-test="report-unreadable">
                    <flux:heading size="sm" level="3">{{ trans_choice(':count row could not be read|:count rows could not be read', $statement->lines_unreadable, ['count' => $statement->lines_unreadable]) }}</flux:heading>
                    <ul class="mt-2 list-disc ps-5 text-sm text-zinc-600 dark:text-zinc-300">
                        @foreach ($statement->unreadableRows() as $problem)
                            <li data-row="{{ $problem['row'] }}">{{ __('Row :row: :reason', ['row' => $problem['row'], 'reason' => $problem['reason']]) }}</li>
                        @endforeach
                    </ul>
                    @if ($statement->lines_unreadable > count($statement->unreadableRows()))
                        <flux:text class="mt-1 text-sm">{{ __('Only the first :count are listed.', ['count' => count($statement->unreadableRows())]) }}</flux:text>
                    @endif
                </div>
            @endif
        @endif

        <flux:text class="mt-4 text-sm">
            {{ __('Uploaded :date by :name.', ['date' => $statement->created_at?->timezone('Africa/Lagos')->format('d/m/Y H:i'), 'name' => $statement->uploadedBy?->name ?? __('a former member')]) }}
        </flux:text>
    </section>

    @if (! $reading && $statement->lines_credit > 0)
        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="statement-matching">
            <flux:heading size="lg" level="2">{{ __('Matching') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Credits with a bill\'s payment reference in the narration, or the amount a bill owes and a name like the tenant\'s, become payments waiting for confirmation in Payments. Agents confirm those of their tenants there.') }}</flux:text>

            <dl class="mt-4 grid gap-4 sm:grid-cols-3">
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Matched') }}</dt>
                    <dd class="text-zinc-900 dark:text-white" data-test="matching-matched">{{ $matching['matched'] }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Waiting for confirmation') }}</dt>
                    <dd class="text-zinc-900 dark:text-white" data-test="matching-waiting">{{ $matching['waiting'] }} @if ($matching['high'] > 0)<span class="text-sm text-zinc-500 dark:text-zinc-400">· {{ __(':count with the reference', ['count' => $matching['high']]) }}</span>@endif</dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Still to match') }}</dt>
                    <dd class="text-zinc-900 dark:text-white" data-test="matching-open">{{ $matching['open'] }}</dd>
                </div>
            </dl>

            @if ($canMatch)
                <div class="mt-4 flex flex-wrap gap-2">
                    @if ($matching['high'] > 0)
                        <flux:button variant="primary" icon="check" wire:click="confirmHighConfidence" wire:confirm="{{ __('Confirm the :count payments matched by their bill reference? Receipts go to the tenants, and a confirmed payment cannot be changed or deleted.', ['count' => $matching['high']]) }}" data-test="confirm-high-confidence">{{ trans_choice('Confirm :count match with the reference|Confirm :count matches with the reference', $matching['high'], ['count' => $matching['high']]) }}</flux:button>
                    @endif
                    @if ($matching['open'] > 0)
                        <flux:button icon="magnifying-glass" wire:click="findMatches" data-test="find-matches">{{ __('Look for matches again') }}</flux:button>
                    @endif
                </div>
            @endif
        </section>
    @endif

    <div class="flex flex-col gap-3">
        <div class="flex flex-wrap items-center gap-2" role="group" aria-label="{{ __('Which lines') }}">
            <flux:button size="sm" :variant="$direction === 'credit' && $status === 'unmatched' ? 'primary' : 'ghost'" wire:click="showCandidates" data-test="show-candidates">{{ __('Credits to match') }}</flux:button>
            <flux:button size="sm" :variant="! $filtered ? 'primary' : 'ghost'" wire:click="clearFilters" data-test="show-all-lines">{{ __('All lines') }}</flux:button>
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" type="search" :placeholder="__('Search the narration or reference')" :aria-label="__('Search lines')" maxlength="100" size="sm" data-test="line-search" />
            <flux:select wire:model.live="direction" :aria-label="__('Credit or debit')" size="sm" data-test="direction-filter">
                <option value="">{{ __('Credits and debits') }}</option>
                @foreach ($directions as $option)
                    <option value="{{ $option->value }}">{{ __($option->label()) }}</option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="status" :aria-label="__('Match status')" size="sm" data-test="match-filter">
                <option value="">{{ __('Any match status') }}</option>
                @foreach ($statuses as $option)
                    <option value="{{ $option->value }}">{{ __($option->label()) }}</option>
                @endforeach
            </flux:select>
        </div>
    </div>

    @if ($lines->isEmpty())
        <x-empty-state icon="magnifying-glass" :heading="$filtered ? __('No line matches') : __('No lines from this statement')" :description="$filtered ? __('Clear the filters to see every line.') : __('Every transaction in it was already brought in by an earlier statement, or it is still being read.')" />
    @else
        <ul class="divide-y divide-zinc-100 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-800 dark:border-zinc-700 dark:bg-zinc-900" data-test="statement-lines">
            @foreach ($lines as $line)
                @php($credit = $line->direction === \Modules\Collections\Enums\LineDirection::Credit)
                <li class="flex flex-col gap-2 p-4 sm:flex-row sm:items-center sm:justify-between" wire:key="line-{{ $line->id }}" data-line="{{ $line->id }}" data-direction="{{ $line->direction->value }}" @if ($line->isOfferedForMatching()) data-candidate @endif>
                    <div class="flex min-w-0 flex-col">
                        <span class="break-words text-zinc-900 dark:text-white">{{ $line->narration !== '' ? $line->narration : __('(no narration)') }}</span>
                        <span class="text-sm text-zinc-500 dark:text-zinc-400">
                            {{ $line->transaction_date->format('d/m/Y') }}@if ($line->counterparty_name) · {{ $line->counterparty_name }}@endif @if ($line->reference) · <span class="font-mono">{{ $line->reference }}</span>@endif
                        </span>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 sm:justify-end">
                        <span class="font-medium {{ $credit ? 'text-emerald-700 dark:text-emerald-400' : 'text-zinc-700 dark:text-zinc-300' }}" data-test="amount-{{ $line->id }}">{{ $credit ? '+' : '−' }}{{ $line->amount_kobo->format() }}</span>
                        @if ($line->balance_kobo)
                            <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('bal. :amount', ['amount' => $line->balance_kobo->format()]) }}</span>
                        @endif
                        <flux:badge size="sm" :color="$line->match_status->color()" data-test="match-{{ $line->id }}">{{ __($line->match_status->label()) }}</flux:badge>
                    </div>
                </li>
                @if ($line->payment_id !== null || ($credit && $line->isOfferedForMatching() && ($canMatch || $line->match_attempts > 0)))
                    <li class="flex flex-wrap items-center gap-2 bg-zinc-50 px-4 py-2 text-sm dark:bg-zinc-800/50" wire:key="line-match-{{ $line->id }}" data-line-match="{{ $line->id }}">
                        @if ($line->payment_id !== null)
                            <span class="text-zinc-700 dark:text-zinc-300" data-test="line-tenant-{{ $line->id }}">{{ $tenantNames[$line->tenant_profile_id] ?? __('A tenant') }}</span>
                            @if ($line->match_confidence)
                                <span class="text-zinc-500 dark:text-zinc-400" data-test="line-confidence-{{ $line->id }}">· {{ __($line->match_confidence->label()) }}</span>
                            @endif
                            <a href="{{ route('workspace.payments.show', [$organization, $line->payment_id]) }}" wire:navigate class="underline" data-test="line-payment-{{ $line->id }}">{{ __('Open the payment') }}</a>
                        @else
                            @if ($line->match_attempts > 0)
                                <span class="text-zinc-500 dark:text-zinc-400" data-test="line-rejected-{{ $line->id }}">{{ __('Its matched payment was rejected.') }}</span>
                            @endif
                            @if ($canMatch)
                                <a href="{{ route('workspace.statements.match', [$organization, $statement->id, $line->id]) }}" wire:navigate class="underline" data-test="match-by-hand-{{ $line->id }}">{{ __('Match by hand') }}</a>
                            @endif
                        @endif
                    </li>
                @endif
            @endforeach
        </ul>

        <flux:pagination :paginator="$lines" />
    @endif
</x-page>
