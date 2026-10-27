<?php

declare(strict_types=1);

namespace Modules\Import\Livewire;

use App\Media\UploadRefused;
use App\Media\UploadValidator;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Modules\Import\Batches\ImportBatches;
use Modules\Import\Contracts\ImportTarget;
use Modules\Import\Contracts\ImportTargets;
use Modules\Import\Livewire\Concerns\InWorkspace;
use Modules\Import\Models\ImportBatch;

/**
 * New import, /o/{organization}/imports/new (DATA_IMPORT.md § 2, step 1):
 * choose an .xlsx workbook (the template or the agency's own) or a .csv
 * file and, for a CSV, optionally what it holds. The file is checked on
 * the server (UploadValidator, collection `imports`) and stored in the
 * media library; the next page maps its columns.
 */
#[Layout('layouts::app')]
#[Title('New import')]
final class NewImport extends Component
{
    use InWorkspace, WithFileUploads;

    /** @var TemporaryUploadedFile|null */
    public $file = null;

    public string $target = '';

    public function mount(OrganizationContext $context): void
    {
        $this->enterWorkspace($context);
        $this->authorize('create', ImportBatch::class);
    }

    public function upload(ImportBatches $batches, UploadValidator $validator, ImportTargets $targets): void
    {
        $this->authorize('create', ImportBatch::class);

        $this->validate([
            'file' => ['required', 'file', $validator->rule(ImportBatch::COLLECTION)],
            'target' => ['nullable', 'string', Rule::in(array_map(static fn (ImportTarget $t): string => $t->key(), $targets->all()))],
        ], ['file.required' => __('Choose the file to import.')], ['file' => __('file'), 'target' => __('contents')]);

        $file = $this->file;

        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages(['file' => __('Choose the file to import.')]);
        }

        try {
            $batch = $batches->upload($this->user(), $file, $this->target !== '' ? $this->target : null);
        } catch (UploadRefused $refused) {
            throw ValidationException::withMessages(['file' => $refused->getMessage()]);
        }

        $this->redirectRoute('workspace.imports.show', ['organization' => $this->organizationSlug(), 'batch' => $batch->id], navigate: true);
    }

    public function render(OrganizationContext $context, ImportTargets $targets): View
    {
        return view('import::livewire.new-import', [
            'organization' => $context->get(),
            'targets' => $targets->all(),
        ]);
    }

    private function organizationSlug(): string
    {
        return app(OrganizationContext::class)->get()->slug;
    }
}
