{{--
    One import (/o/{organization}/imports/{batch}; Modules\Import\Livewire\ImportBatchPage).
    Shows the step the batch is at. Buttons show only for what the member
    may do; every action checks its policy again.
--}}
@use('Modules\Import\Enums\ImportStatus')
@use('Modules\Import\Contracts\ImportAction')
@php
    $status = $batch->status;
    $step = match (true) {
        $status === ImportStatus::Uploaded => 'map',
        $status === ImportStatus::DryRunFailed => 'dry-run',
        $status === ImportStatus::DryRunPassed => 'review',
        $status === ImportStatus::Committing => 'commit',
        default => 'report',
    };
    $targetLabels = collect($targets)->mapWithKeys(fn ($t) => [$t->key() => $t->label()]);
@endphp

<x-page :heading="$batch->file_name" :description="collect([__($status->label()), $batch->createdBy?->name ? __('uploaded by :name', ['name' => $batch->createdBy->name]) : null, $batch->created_at?->format('d/m/Y H:i')])->filter()->join(' · ')">
    <x-slot:actions>
        <flux:button :href="route('workspace.imports.index', $organization)" wire:navigate icon="arrow-left">{{ __('All imports') }}</flux:button>
    </x-slot:actions>

    @include('import::livewire.partials.steps', ['current' => $step])

    @if ($notice)
        <flux:callout variant="success" icon="check-circle" :heading="$notice" role="status" data-test="import-notice" />
    @endif
    @if ($problem)
        <flux:callout variant="danger" icon="exclamation-triangle" :heading="$problem" role="alert" data-test="import-problem" />
    @endif
    @if ($batch->failure_reason && $status === ImportStatus::PartiallyFailed)
        <flux:callout variant="danger" icon="exclamation-triangle" :heading="$batch->failure_reason" />
    @endif

    {{-- Map columns (and run the dry run) --}}
    @if ($status->isPreparing())
        <section class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="mapping">
            <div>
                <flux:heading size="lg" level="2">{{ __('Match the columns') }}</flux:heading>
                <flux:text class="mt-1">{{ __('We matched what we could. Check each sheet, change anything that is wrong, then run the dry run. Nothing is saved yet.') }}</flux:text>
            </div>

            @if (! $hasFile)
                <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('The uploaded file is no longer kept (files are deleted after 30 days). Upload it again.')" />
            @endif

            @foreach ($mappedSheets as $sheet)
                <div class="flex flex-col gap-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" wire:key="sheet-{{ $sheet->index }}" data-sheet="{{ $sheet->index }}">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <flux:heading level="3">{{ $batch->file_type->value === 'csv' ? __('Your CSV file') : __('Sheet “:name”', ['name' => $sheet->name]) }}</flux:heading>
                            <flux:text class="text-sm">{{ $sheet->headers === [] ? __('Empty: nothing to import.') : trans_choice(':count column|:count columns', count($sheet->headers)) }}</flux:text>
                        </div>
                        @if ($sheet->headers !== [])
                            <div class="w-full sm:max-w-xs">
                                <flux:select wire:model.live="sheets.{{ $sheet->index }}.target" :label="__('This sheet holds')" :disabled="! $canEdit">
                                    <option value="">{{ __('Nothing to import') }}</option>
                                    @foreach ($targets as $target)
                                        <option value="{{ $target->key() }}">{{ __($target->label()) }}</option>
                                    @endforeach
                                </flux:select>
                                <flux:error name="sheets.{{ $sheet->index }}.target" />
                            </div>
                        @endif
                    </div>

                    @php $chosen = $sheets[$sheet->index]['target'] ?? ''; $chosenTarget = collect($targets)->first(fn ($t) => $t->key() === $chosen); @endphp
                    @if ($chosenTarget && $sheet->headers !== [])
                        <div class="grid gap-3 sm:grid-cols-2">
                            @foreach ($chosenTarget->columns() as $column)
                                <div wire:key="sheet-{{ $sheet->index }}-{{ $column->key }}">
                                    <flux:select wire:model="sheets.{{ $sheet->index }}.columns.{{ $column->key }}" :label="__($column->label).($column->required ? ' *' : '')" :description="$column->help ? __($column->help) : null" :disabled="! $canEdit">
                                        <option value="">{{ $column->required ? __('— choose a column —') : __('— not in my file —') }}</option>
                                        @foreach ($sheet->headers as $position => $header)
                                            @if ($header !== '')
                                                <option value="{{ $position }}">{{ \Modules\Import\Mapping\SheetMapping::letter($position) }}: {{ $header }}</option>
                                            @endif
                                        @endforeach
                                    </flux:select>
                                    <flux:error name="sheets.{{ $sheet->index }}.columns.{{ $column->key }}" />
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach

            @if ($canEdit && $hasFile)
                <form wire:submit="dryRun" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                    <div class="w-full sm:max-w-xs">
                        <flux:input wire:model="saveAs" :label="__('Save this matching as (optional)')" :placeholder="__('e.g. My Excel sheet')" maxlength="80" />
                        <flux:error name="saveAs" />
                    </div>
                    <flux:button type="submit" variant="primary" icon="beaker" wire:loading.attr="disabled" data-test="run-dry-run">{{ __('Run dry run') }}</flux:button>
                    <span wire:loading wire:target="dryRun" class="text-sm text-zinc-500">{{ __('Checking every row…') }}</span>
                </form>
            @endif
        </section>
    @endif

    {{-- Dry-run report --}}
    @if ($report && $status !== ImportStatus::Uploaded)
        <section class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="dry-run-report">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <flux:heading size="lg" level="2">{{ __('Dry run') }}</flux:heading>
                    <flux:text class="mt-1">
                        @if ($report['errors'] > 0)
                            {{ trans_choice('{1} :count error must be fixed before the import. Nothing was saved.|[2,*] :count errors must be fixed before the import. Nothing was saved.', $report['errors']) }}
                        @elseif ($report['rows'] === 0)
                            {{ __('There are no rows to import. Nothing was saved.') }}
                        @else
                            {{ __('No errors. Nothing was saved yet: review what will happen, then commit.') }}
                        @endif
                        @if ($report['warnings'] > 0)
                            {{ trans_choice('{1} :count warning does not stop the import.|[2,*] :count warnings do not stop the import.', $report['warnings']) }}
                        @endif
                    </flux:text>
                </div>
                @if ($hasFile && ($report['errors'] > 0 || $report['warnings'] > 0))
                    <flux:button icon="arrow-down-tray" :href="route('workspace.imports.errors', [$organization, $batch->id])" data-test="download-problems">{{ __('Download the problems (Excel)') }}</flux:button>
                @endif
            </div>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                @foreach ([['rows', __('Rows checked')], [ImportAction::Create->value, __('New')], [ImportAction::Update->value, __('Updated')], [ImportAction::Restore->value, __('Restored')]] as [$key, $label])
                    <div class="rounded-lg bg-zinc-50 p-3 dark:bg-zinc-800">
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $label }}</div>
                        <div class="text-xl font-semibold text-zinc-900 dark:text-white" data-count="{{ $key }}">{{ number_format($key === 'rows' ? $report['rows'] : ($report['counts'][$key] ?? 0)) }}</div>
                    </div>
                @endforeach
            </div>

            @if ($subscription)
                <flux:text class="text-sm" data-test="subscription-effect">
                    @if ($subscription['now'] !== null)
                        {{ __('This file adds :units units to the :now you have. On the :plan plan, :total units cost :price a month (before VAT), against :before now.', ['units' => number_format($subscription['units']), 'now' => number_format($subscription['now']), 'total' => number_format($subscription['now'] + $subscription['units']), 'plan' => $subscription['plan'], 'price' => $subscription['monthly']->format(), 'before' => $subscription['before']?->format()]) }}
                    @else
                        {{ __('This file adds :units units. On the :plan plan, :units units cost :price a month (before VAT; units you already have are added to this).', ['units' => number_format($subscription['units']), 'plan' => $subscription['plan'], 'price' => $subscription['monthly']->format()]) }}
                    @endif
                </flux:text>
            @endif
            @if (($report['yearly_collections_kobo'] ?? 0) > 0)
                <flux:text class="text-sm">{{ __('Expected yearly collections from this file: :amount (counts towards your Transaction Tier).', ['amount' => \App\Support\Money::kobo($report['yearly_collections_kobo'])->format()]) }}</flux:text>
            @endif

            @foreach ($report['sheets'] as $sheet)
                <div class="flex flex-col gap-3 border-t border-zinc-100 pt-4 dark:border-zinc-800" data-report-sheet="{{ $sheet['index'] }}">
                    <flux:heading level="3">{{ __($sheet['label']) }} <span class="font-normal text-zinc-500">· {{ $sheet['name'] }}</span></flux:heading>
                    <flux:text class="text-sm">
                        {{ __(':rows rows: :create new, :update updated, :restore restored, :errors with errors, :warnings warnings.', [
                            'rows' => number_format($sheet['rows']), 'create' => number_format($sheet['counts']['create'] ?? 0), 'update' => number_format($sheet['counts']['update'] ?? 0),
                            'restore' => number_format($sheet['counts']['restore'] ?? 0), 'errors' => number_format($sheet['errors']), 'warnings' => number_format($sheet['warnings']),
                        ]) }}
                    </flux:text>

                    @if ($sheet['issues'] !== [])
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm" data-test="issues">
                                <thead class="text-xs text-zinc-500"><tr><th class="py-1 pe-3">{{ __('Row') }}</th><th class="py-1 pe-3">{{ __('Column') }}</th><th class="py-1">{{ __('Problem') }}</th></tr></thead>
                                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                    @foreach ($sheet['issues'] as $issue)
                                        <tr data-issue-row="{{ $issue['row'] }}">
                                            <td class="py-1 pe-3 align-top">{{ $issue['row'] ?? '—' }}</td>
                                            <td class="py-1 pe-3 align-top">{{ $issue['header'] ? $issue['header'].($issue['letter'] ? ' ('.$issue['letter'].')' : '') : '—' }}</td>
                                            <td class="py-1 align-top {{ $issue['severity'] === 'error' ? 'text-red-700 dark:text-red-400' : 'text-amber-700 dark:text-amber-400' }}">{{ $issue['severity'] === 'warning' ? __('Warning').': ' : '' }}{{ $issue['message'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @if ($sheet['issues_truncated'] ?? false)
                            <flux:text class="text-sm">{{ __('Only the first problems are shown here. The Excel download lists every one, next to its row.') }}</flux:text>
                        @endif
                    @endif

                    @if (($sheet['preview']['rows'] ?? []) !== [])
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm" data-test="preview">
                                <caption class="pb-1 text-left text-xs text-zinc-500">{{ __('How the first rows will look') }}</caption>
                                <thead class="text-xs text-zinc-500"><tr><th class="py-1 pe-3">{{ __('Row') }}</th><th class="py-1 pe-3"></th>@foreach ($sheet['preview']['columns'] as $label)<th class="py-1 pe-3">{{ __($label) }}</th>@endforeach</tr></thead>
                                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                    @foreach ($sheet['preview']['rows'] as $row)
                                        <tr>
                                            <td class="py-1 pe-3">{{ $row['row'] }}</td>
                                            <td class="py-1 pe-3"><flux:badge size="sm">{{ __(ImportAction::from($row['action'])->label()) }}</flux:badge></td>
                                            @foreach ($row['values'] as $value)<td class="py-1 pe-3">{{ $value }}</td>@endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            @endforeach

            @if ($canCommit)
                <div class="flex flex-wrap items-center gap-3 border-t border-zinc-100 pt-4 dark:border-zinc-800">
                    <flux:button variant="primary" icon="check" wire:click="commit" wire:confirm="{{ __('Import these records now? You can roll the import back for :days days if none of them has been used.', ['days' => config('import.rollback_days', 7)]) }}" data-test="commit-import">{{ __('Commit import') }}</flux:button>
                    <flux:text class="text-sm">{{ __('Tenants are not contacted. You choose when to invite them.') }}</flux:text>
                </div>
            @endif
        </section>
    @endif

    {{-- Commit progress --}}
    @if ($status === ImportStatus::Committing)
        <section wire:poll.5s class="flex flex-col gap-3 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="progress">
            <flux:heading size="lg" level="2">{{ __('Importing…') }}</flux:heading>
            @php $percent = $batch->rows_total > 0 ? intdiv($batch->rows_done * 100, $batch->rows_total) : 0; @endphp
            <div class="h-3 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percent }}" aria-label="{{ __('Import progress') }}">
                <div class="h-full rounded-full bg-primary-600" style="width: {{ $percent }}%"></div>
            </div>
            <flux:text class="text-sm">{{ __(':done of :total rows', ['done' => number_format($batch->rows_done), 'total' => number_format($batch->rows_total)]) }}</flux:text>
        </section>
    @endif

    {{-- Report and rollback --}}
    @if ($status->hasCommitted() || $status === ImportStatus::RolledBack)
        <section class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="import-report">
            <div>
                <flux:heading size="lg" level="2">{{ $status === ImportStatus::RolledBack ? __('Rolled back') : __('Import report') }}</flux:heading>
                <flux:text class="mt-1">
                    @if ($status === ImportStatus::RolledBack)
                        {{ __('Rolled back on :date. The records this import added are archived.', ['date' => $batch->rolled_back_at?->format('d/m/Y H:i')]) }}
                    @else
                        {{ __('Committed on :date by :name.', ['date' => $batch->committed_at?->format('d/m/Y H:i'), 'name' => $batch->committedBy?->name ?? __('a member')]) }}
                    @endif
                </flux:text>
            </div>

            <ul class="flex flex-col gap-1 text-sm">
                @foreach (($batch->result_counts['targets'] ?? []) as $target => $counts)
                    <li data-result="{{ $target }}">
                        <span class="font-medium">{{ __($targetLabels[$target] ?? $target) }}:</span>
                        {{ __(':create new, :update updated, :restore restored, :skip skipped', ['create' => $counts['create'] ?? 0, 'update' => $counts['update'] ?? 0, 'restore' => $counts['restore'] ?? 0, 'skip' => $counts['skip'] ?? 0]) }}
                    </li>
                @endforeach
            </ul>

            @if (($batch->result_counts['skipped'] ?? []) !== [])
                <div>
                    <flux:heading level="3">{{ __('Skipped rows') }}</flux:heading>
                    <ul class="mt-1 list-disc ps-5 text-sm">
                        @foreach ($batch->result_counts['skipped'] as $skipped)
                            <li>{{ __(':kind row :row: :reason', ['kind' => $targetLabels[$skipped['target']] ?? $skipped['target'], 'row' => $skipped['row'], 'reason' => $skipped['reason']]) }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @foreach ($panels as $panel)
                @livewire($panel, ['batchId' => $batch->id], key('import-panel-'.$panel.'-'.$batch->id))
            @endforeach

            @if ($canRollback)
                <div class="flex flex-wrap items-center gap-3 border-t border-zinc-100 pt-4 dark:border-zinc-800">
                    <flux:button variant="danger" icon="arrow-uturn-left" wire:click="rollback" wire:confirm="{{ __('Roll this import back? The records it added are archived. Records it updated keep the changes.') }}" data-test="rollback-import">{{ __('Roll back') }}</flux:button>
                    <flux:text class="text-sm">{{ __('Possible until :date, while none of the records has been used.', ['date' => $batch->rollbackEndsAt()?->format('d/m/Y H:i')]) }}</flux:text>
                </div>
            @endif
        </section>
    @endif
</x-page>
