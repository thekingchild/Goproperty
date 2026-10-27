<?php

declare(strict_types=1);

namespace Modules\Collections\Livewire;

use App\Exports\Livewire\WithListExports;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Collections\Enums\StatementStatus;
use Modules\Collections\Lists\StatementList;
use Modules\Collections\Livewire\Concerns\InWorkspace;
use Modules\Collections\Models\BankStatementImport;
use Modules\Collections\Statements\StatementImports;

/**
 * Bank statements, /o/{organization}/statements (ONBOARDING_COMPLIANCE.md
 * § 4.1; P03-07a): the uploaded statements, newest first, 25 a page, with
 * each one's account (masked), period and report counts, filtered by
 * account and status (in the URL). "Upload a statement" for members who
 * may. Rows come from Lists\StatementList, which also exports them.
 */
#[Layout('layouts::app')]
#[Title('Bank statements')]
final class StatementIndex extends Component
{
    use InWorkspace, WithListExports, WithPagination;

    public const int PER_PAGE = 25;

    /** An account id, or empty. */
    #[Url(except: '')]
    public string $account = '';

    #[Url(except: '')]
    public string $status = '';

    public function mount(OrganizationContext $context): void
    {
        $this->enterWorkspace($context);
        $this->authorize('viewAny', BankStatementImport::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['account', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('account', 'status');
        $this->resetPage();
    }

    public function render(OrganizationContext $context, StatementList $list, StatementImports $imports): View
    {
        $this->authorize('viewAny', BankStatementImport::class);
        $user = $this->user();
        $filters = $list->filters($this->listExports()['bank_statements']);
        $statements = $list->query($user, $filters)->with(['bankAccount', 'uploadedBy:id,name'])->paginate(self::PER_PAGE);

        return view('collections::livewire.statement-index', [
            'organization' => $context->get(),
            'statements' => $statements,
            'filtered' => $filters !== [],
            'accounts' => $imports->accounts(),
            'statuses' => StatementStatus::cases(),
            'canUpload' => $user->can('create', BankStatementImport::class),
            'reading' => $statements->getCollection()->contains(fn (BankStatementImport $statement): bool => $statement->status === StatementStatus::Processing),
        ]);
    }

    protected function listExports(): array
    {
        return ['bank_statements' => ['account' => $this->account, 'status' => $this->status]];
    }
}
