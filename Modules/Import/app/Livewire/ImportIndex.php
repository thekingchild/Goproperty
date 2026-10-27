<?php

declare(strict_types=1);

namespace Modules\Import\Livewire;

use App\Exports\Livewire\WithListExports;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Import\Lists\ImportBatchList;
use Modules\Import\Livewire\Concerns\InWorkspace;
use Modules\Import\Models\ImportBatch;

/**
 * Imports, /o/{organization}/imports: the organization's import batches,
 * newest first (DATA_IMPORT.md § 5: who ran which file, its status and
 * counts), with "New import" and the template download for those who may
 * import. `imports.view` opens it (ImportBatchPolicy) and exports it
 * (Lists\ImportBatchList, P02-07).
 */
#[Layout('layouts::app')]
#[Title('Imports')]
final class ImportIndex extends Component
{
    use InWorkspace, WithListExports, WithPagination;

    public const int PER_PAGE = 25;

    public function mount(OrganizationContext $context): void
    {
        $this->enterWorkspace($context);
        $this->authorize('viewAny', ImportBatch::class);
    }

    public function render(OrganizationContext $context, ImportBatchList $list): View
    {
        return view('import::livewire.import-index', [
            'organization' => $context->get(),
            'batches' => $list->query($this->user(), [])->with('createdBy:id,name')->paginate(self::PER_PAGE),
            'canImport' => $this->user()->can('create', ImportBatch::class),
        ]);
    }

    /** The import history, which exports (P02-07, Lists\ImportBatchList); no filters. */
    protected function listExports(): array
    {
        return ['imports' => []];
    }
}
