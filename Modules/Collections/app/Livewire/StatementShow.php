<?php

declare(strict_types=1);

namespace Modules\Collections\Livewire;

use App\Exports\Livewire\WithListExports;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Collections\Enums\LineDirection;
use Modules\Collections\Enums\MatchStatus;
use Modules\Collections\Enums\StatementStatus;
use Modules\Collections\Lists\StatementLineList;
use Modules\Collections\Livewire\Concerns\InWorkspace;
use Modules\Collections\Models\BankStatementImport;

/**
 * One uploaded statement, /o/{organization}/statements/{statement}
 * (P03-07a): its account, period and import report (transactions read,
 * lines added, duplicates skipped, debits kept but never matched,
 * unreadable rows with the reason), then its lines in the file's order,
 * 50 a page, filtered by direction, match status and a search (in the
 * URL). Credits still to match are marked as such: they are what matching
 * works on (P03-07b); debits are shown for the record and never offered.
 * While the statement is being read the page polls. Rows come from
 * Lists\StatementLineList, which also exports them.
 */
#[Layout('layouts::app')]
#[Title('Bank statement')]
final class StatementShow extends Component
{
    use InWorkspace, WithListExports, WithPagination;

    public const int PER_PAGE = 50;

    #[Locked]
    public int $statementId;

    #[Url(except: '')]
    public string $direction = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function mount(OrganizationContext $context, BankStatementImport $statement): void
    {
        $this->enterWorkspace($context);
        $this->authorize('view', $statement);
        $this->statementId = $statement->id;
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['direction', 'status', 'search'], true)) {
            $this->resetPage();
        }
    }

    /** Credits still to match: the candidates for matching. */
    public function showCandidates(): void
    {
        $this->direction = LineDirection::Credit->value;
        $this->status = MatchStatus::Unmatched->value;
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('direction', 'status', 'search');
        $this->resetPage();
    }

    public function render(OrganizationContext $context, StatementLineList $list): View
    {
        $statement = BankStatementImport::query()->with(['bankAccount', 'uploadedBy:id,name'])->findOrFail($this->statementId);
        // Checked on every render, not only when the page opened.
        $this->authorize('view', $statement);
        $user = $this->user();
        $filters = $list->filters($this->listExports()['bank_statement_lines']);
        $lines = $list->query($user, $filters)->paginate(self::PER_PAGE);

        return view('collections::livewire.statement-show', [
            'organization' => $context->get(),
            'statement' => $statement,
            'lines' => $lines,
            'filtered' => count($filters) > 1,
            'directions' => LineDirection::cases(),
            'statuses' => MatchStatus::cases(),
            'reading' => $statement->status === StatementStatus::Processing,
            'canDownload' => $user->can('downloadFile', $statement),
        ]);
    }

    protected function listExports(): array
    {
        return ['bank_statement_lines' => [
            'statement' => (string) $this->statementId,
            'direction' => $this->direction,
            'status' => $this->status,
            'search' => $this->search,
        ]];
    }
}
