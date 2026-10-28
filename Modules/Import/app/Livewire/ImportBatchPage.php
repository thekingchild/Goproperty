<?php

declare(strict_types=1);

namespace Modules\Import\Livewire;

use App\Marketing\PlanCatalogue;
use App\Models\Organization;
use App\Support\Money;
use App\Tenancy\BlockedWhileImpersonating;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Import\Batches\ImportBatches;
use Modules\Import\Batches\ImportRefused;
use Modules\Import\Contracts\ImportReportPanels;
use Modules\Import\Contracts\ImportTargets;
use Modules\Import\Enums\ImportStatus;
use Modules\Import\Livewire\Concerns\InWorkspace;
use Modules\Import\Mapping\MappingSuggester;
use Modules\Import\Mapping\SheetMapping;
use Modules\Import\Models\ImportBatch;
use Modules\Properties\Contracts\UnitDirectory;

/**
 * One import, /o/{organization}/imports/{batch}: the steps after the
 * upload (DATA_IMPORT.md § 2) on one page, by the batch's status: map the
 * columns (suggested, saved mappings first) → dry run → review the report
 * (counts, errors with the error workbook, warnings, preview, effect on the
 * subscription) → commit (queued; the progress bar polls) → report and,
 * for 7 days while unused, rollback. Once committed, the report shows the
 * steps other modules offer (ImportReportPanels: "Invite tenants now"),
 * each a component that checks its own permissions and does nothing until
 * the agency asks (DATA_IMPORT.md § 2.6).
 *
 * Every action checks ImportBatchPolicy (ImportBatches checks again).
 * Commit and rollback are refused while GoProperty staff impersonate
 * (DATA_IMPORT.md § 5): staff can still open the page and see the results.
 */
#[Layout('layouts::app')]
#[Title('Import')]
final class ImportBatchPage extends Component
{
    use InWorkspace;

    #[Locked]
    public int $batchId;

    /** @var array<int, array{target: string, columns: array<string, string>}> sheet index => mapping */
    public array $sheets = [];

    public string $saveAs = '';

    #[Locked]
    public ?string $notice = null;

    #[Locked]
    public ?string $problem = null;

    public function mount(OrganizationContext $context, ImportBatch $batch): void
    {
        $this->enterWorkspace($context);
        $this->authorize('view', $batch);
        $this->batchId = $batch->id;
        $this->fillMapping($batch);
    }

    /** Choosing what a sheet holds suggests its columns again. */
    public function updated(string $property): void
    {
        if (preg_match('/^sheets\.(\d+)\.target$/', $property, $match) !== 1) {
            return;
        }

        $index = (int) $match[1];
        $sheet = collect($this->batch()->sheets())->first(fn (SheetMapping $s): bool => $s->index === $index);

        if ($sheet === null) {
            return;
        }

        $target = $this->sheets[$index]['target'] ?? '';
        $suggested = $target === '' ? $sheet->mappedTo(null, []) : app(MappingSuggester::class)->suggest($sheet, $target);
        $this->sheets[$index]['columns'] = array_map('strval', $suggested->columns);
    }

    public function dryRun(ImportBatches $batches): void
    {
        $batch = $this->batch();
        $this->authorize('update', $batch);
        [$this->notice, $this->problem] = [null, null];

        $input = [];

        foreach ($this->sheets as $index => $sheet) {
            $input[$index] = ['target' => $sheet['target'], 'columns' => $sheet['columns']];
        }

        try {
            $batch = $batches->map($this->user(), $batch, $input, $this->saveAs);
            $batch = $batches->dryRun($this->user(), $batch);
        } catch (ImportRefused $refused) {
            $this->problem = $refused->getMessage();

            return;
        }

        $this->saveAs = '';
        $this->fillMapping($batch);
    }

    #[BlockedWhileImpersonating]
    public function commit(ImportBatches $batches): void
    {
        $batch = $this->batch();
        $this->authorize('commit', $batch);
        [$this->notice, $this->problem] = [null, null];

        try {
            $batches->commit($this->user(), $batch);
            $this->notice = __('The import has started. You can leave this page; it carries on.');
        } catch (ImportRefused $refused) {
            $this->problem = $refused->getMessage();
        }
    }

    #[BlockedWhileImpersonating]
    public function rollback(ImportBatches $batches): void
    {
        $batch = $this->batch();
        $this->authorize('rollback', $batch);
        [$this->notice, $this->problem] = [null, null];

        try {
            $batches->rollback($this->user(), $batch);
            $this->notice = __('The import was rolled back. Its records are archived.');
        } catch (ImportRefused $refused) {
            $this->problem = $refused->getMessage();
        }
    }

    public function render(OrganizationContext $context, ImportTargets $targets, PlanCatalogue $plans, ImportReportPanels $panels): View
    {
        $batch = $this->batch();
        $user = $this->user();
        $report = $batch->dry_run_report;
        $units = is_array($report) && is_int($report['units'] ?? null) ? $report['units'] : 0;

        return view('import::livewire.import-batch-page', [
            'organization' => $context->get(),
            'batch' => $batch,
            'mappedSheets' => $batch->sheets(),
            'targets' => $targets->all(),
            'report' => is_array($report) ? $report : null,
            'subscription' => $units > 0 ? $this->subscription($context->get(), $plans, $units, $batch->status->isPreparing() ? self::billableUnits() : null) : null,
            'canEdit' => $user->can('update', $batch),
            'canCommit' => $batch->status === ImportStatus::DryRunPassed && $user->can('commit', $batch),
            'canRollback' => $batch->status->hasCommitted() && $batch->rollbackEndsAt()?->isFuture() === true && $user->can('rollback', $batch),
            'hasFile' => $batch->media_id !== null,
            // Other modules' next steps (Invite tenants now): only once committed, never on their own.
            'panels' => $batch->status->hasCommitted() ? $panels->all() : [],
        ]);
    }

    /**
     * The subscription effect of the units in the file (DATA_IMPORT.md
     * § 2.3), from the plan catalogue only until SaaS billing exists (P02
     * decision 6): the organization's chosen plan (else the best one for
     * the total), priced for the units it has now plus the file's. `$now`
     * is the billable count before the import (UnitDirectory::
     * countBillable(), the Properties module's contract), known until the
     * import is committed; null shows the file's units alone.
     *
     * @return array{units: int, now: int|null, plan: string, before: Money|null, monthly: Money}
     */
    private function subscription(Organization $organization, PlanCatalogue $plans, int $units, ?int $now): array
    {
        $total = $units + ($now ?? 0);
        $plan = collect($plans->plans())->first(fn ($plan): bool => $plan->key === $organization->signup_plan_key) ?? $plans->bestFor($total);

        return [
            'units' => $units,
            'now' => $now,
            'plan' => $plan->name,
            'before' => $now !== null ? $plan->priceFor($now) : null,
            'monthly' => $plan->priceFor($total),
        ];
    }

    /** The organization's billable units now, when the Properties module is on. */
    private static function billableUnits(): ?int
    {
        return app()->bound(UnitDirectory::class) ? app(UnitDirectory::class)->countBillable() : null;
    }

    private function fillMapping(ImportBatch $batch): void
    {
        $this->sheets = [];

        foreach ($batch->sheets() as $sheet) {
            $this->sheets[$sheet->index] = [
                'target' => (string) $sheet->target,
                'columns' => array_map('strval', $sheet->columns),
            ];
        }
    }

    private function batch(): ImportBatch
    {
        return ImportBatch::query()->findOrFail($this->batchId);
    }
}
