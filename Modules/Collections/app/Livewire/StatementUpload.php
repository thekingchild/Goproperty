<?php

declare(strict_types=1);

namespace Modules\Collections\Livewire;

use App\Tenancy\BlockedWhileImpersonating;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Modules\Collections\Enums\DateOrder;
use Modules\Collections\Enums\StatementField;
use Modules\Collections\Livewire\Concerns\InWorkspace;
use Modules\Collections\Models\BankStatementImport;
use Modules\Collections\Statements\StatementImports;

/**
 * Upload a bank statement, /o/{organization}/statements/upload
 * (ONBOARDING_COMPLIANCE.md § 4.1; P03-07a). Two steps: choose the bank
 * account and the file (CSV or Excel, as the bank exports it); then check
 * which column holds each field, with the account's saved mapping (or one
 * worked out from the headers) filled in and the first rows shown, and
 * import. Nothing is stored before Import; the server checks the file, the
 * account and the mapping again then (Statements\StatementImports).
 *
 * Org-owners and org-admins (`statements.import`); never while staff
 * impersonate, nor while the organization is suspended.
 */
#[Layout('layouts::app')]
#[Title('Upload a bank statement')]
final class StatementUpload extends Component
{
    use InWorkspace, WithFileUploads;

    public string $accountId = '';

    /** @var TemporaryUploadedFile|null */
    public $file = null;

    /** @var array<string, string> field => column position, or empty */
    public array $columns = [];

    public string $dateOrder = 'dmy';

    /** Whether the columns step is showing. */
    #[Locked]
    public bool $mapping = false;

    /** @var array<int, string> the statement's column names by position */
    #[Locked]
    public array $headings = [];

    /** @var list<list<string>> the first rows under the header */
    #[Locked]
    public array $sample = [];

    #[Locked]
    public bool $savedMapping = false;

    public function mount(OrganizationContext $context, StatementImports $imports): void
    {
        $this->enterWorkspace($context);
        $this->authorize('create', BankStatementImport::class);
        $this->accountId = (string) ($imports->accounts()->first()->id ?? '');
    }

    /** A new file or account starts again at the first step. */
    public function updated(string $property): void
    {
        if ($property === 'file' || $property === 'accountId') {
            $this->mapping = false;
            $this->reset('columns', 'headings', 'sample', 'savedMapping');
        }
    }

    /** Step 1: read the statement's header and offer a mapping (nothing is stored). */
    #[BlockedWhileImpersonating]
    public function read(StatementImports $imports): void
    {
        $this->authorize('create', BankStatementImport::class);
        $preview = $imports->preview($this->user(), $this->accountId, $this->chosenFile());

        $this->headings = $preview->layout->columns();
        $this->sample = $preview->sample;
        $this->savedMapping = $preview->savedMappingUsed;
        $this->dateOrder = $preview->mapping->dateOrder->value;
        $this->columns = [];

        foreach (StatementField::cases() as $field) {
            $column = $preview->mapping->column($field);
            $this->columns[$field->value] = $column === null ? '' : (string) $column;
        }

        $this->mapping = true;
    }

    /** Step 2: store the statement with this mapping and read its lines. */
    #[BlockedWhileImpersonating]
    public function import(StatementImports $imports): void
    {
        $this->authorize('create', BankStatementImport::class);
        $statement = $imports->upload($this->user(), $this->accountId, $this->chosenFile(), [
            'columns' => $this->columns,
            'date_order' => $this->dateOrder,
        ]);

        session()->flash('statement-status', __('Statement uploaded.'));
        $this->redirectRoute('workspace.statements.show', ['organization' => app(OrganizationContext::class)->get()->slug, 'statement' => $statement->id], navigate: true);
    }

    public function render(OrganizationContext $context, StatementImports $imports): View
    {
        return view('collections::livewire.statement-upload', [
            'organization' => $context->get(),
            'accounts' => $imports->accounts(),
            'fields' => StatementField::cases(),
            'dateOrders' => DateOrder::cases(),
        ]);
    }

    /** @throws ValidationException */
    private function chosenFile(): UploadedFile
    {
        if (! $this->file instanceof UploadedFile) {
            throw ValidationException::withMessages(['file' => __('Choose the statement file.')]);
        }

        return $this->file;
    }
}
