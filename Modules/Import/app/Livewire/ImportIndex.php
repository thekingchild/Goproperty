<?php

declare(strict_types=1);

namespace Modules\Import\Livewire;

use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Import\Livewire\Concerns\InWorkspace;
use Modules\Import\Models\ImportBatch;

/**
 * Imports, /o/{organization}/imports: the organization's import batches,
 * newest first (DATA_IMPORT.md § 5: who ran which file, its status and
 * counts), with "New import" and the template download for those who may
 * import. `imports.view` opens it (ImportBatchPolicy).
 */
#[Layout('layouts::app')]
#[Title('Imports')]
final class ImportIndex extends Component
{
    use InWorkspace, WithPagination;

    public const int PER_PAGE = 25;

    public function mount(OrganizationContext $context): void
    {
        $this->enterWorkspace($context);
        $this->authorize('viewAny', ImportBatch::class);
    }

    public function render(OrganizationContext $context): View
    {
        return view('import::livewire.import-index', [
            'organization' => $context->get(),
            'batches' => ImportBatch::query()->with('createdBy:id,name')->latest('id')->paginate(self::PER_PAGE),
            'canImport' => $this->user()->can('create', ImportBatch::class),
        ]);
    }
}
