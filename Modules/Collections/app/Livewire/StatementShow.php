<?php

declare(strict_types=1);

namespace Modules\Collections\Livewire;

use App\Exports\Livewire\WithListExports;
use App\Tenancy\BlockedWhileImpersonating;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Collections\Enums\LineDirection;
use Modules\Collections\Enums\MatchConfidence;
use Modules\Collections\Enums\MatchStatus;
use Modules\Collections\Enums\StatementStatus;
use Modules\Collections\Lists\StatementLineList;
use Modules\Collections\Livewire\Concerns\InWorkspace;
use Modules\Collections\Matching\LineMatches;
use Modules\Collections\Models\BankStatementImport;
use Modules\Collections\Models\BankStatementLine;
use Modules\Tenants\Contracts\TenantDirectory;

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
 *
 * Matching (P03-07b, Matching\LineMatches): how many credits are matched,
 * waiting for confirmation (high confidence among them) or still to
 * match; each matched line names its tenant and links its payment. With
 * `statements.match` (owners and administrators; never while staff
 * impersonate or the organization is suspended): "Look for matches
 * again", "Confirm the high-confidence matches" (bulk) and, on each
 * credit still to match, "Match by hand" (Livewire\LineMatch).
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

    /** What the last matching action did. */
    public ?string $notice = null;

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

    /** Matching again of the statement's credits nobody has tried yet, on the queue. */
    #[BlockedWhileImpersonating]
    public function findMatches(LineMatches $matches): void
    {
        $statement = $this->statement();
        $this->authorize('match', $statement);
        $matches->requestMatching($this->user(), $statement);
        $this->notice = __('Looking for matches. Payments found wait for confirmation in Payments.');
    }

    /** Confirm the statement's high-confidence matches still waiting, in one go. */
    #[BlockedWhileImpersonating]
    public function confirmHighConfidence(LineMatches $matches): void
    {
        $statement = $this->statement();
        $this->authorize('match', $statement);
        $result = $matches->confirmHighConfidence($this->user(), $statement);
        $this->notice = trans_choice(':count payment confirmed.|:count payments confirmed.', $result->confirmed, ['count' => $result->confirmed]);

        if ($result->refused > 0) {
            $this->notice .= ' '.trans_choice(':count could not be confirmed and still waits in Payments: :reason|:count could not be confirmed and still wait in Payments: :reason', $result->refused, ['count' => $result->refused, 'reason' => $result->problems[0] ?? '']);
        }
    }

    public function clearFilters(): void
    {
        $this->reset('direction', 'status', 'search');
        $this->resetPage();
    }

    public function render(OrganizationContext $context, StatementLineList $list, TenantDirectory $tenants): View
    {
        $statement = BankStatementImport::query()->with(['bankAccount', 'uploadedBy:id,name'])->findOrFail($this->statementId);
        // Checked on every render, not only when the page opened.
        $this->authorize('view', $statement);
        $user = $this->user();
        $filters = $list->filters($this->listExports()['bank_statement_lines']);
        $lines = $list->query($user, $filters)->paginate(self::PER_PAGE);
        $tenantIds = array_values(array_unique(array_filter(array_map(static fn (BankStatementLine $line): ?int => $line->tenant_profile_id, $lines->items()))));

        return view('collections::livewire.statement-show', [
            'organization' => $context->get(),
            'statement' => $statement,
            'lines' => $lines,
            'filtered' => count($filters) > 1,
            'directions' => LineDirection::cases(),
            'statuses' => MatchStatus::cases(),
            'reading' => $statement->status === StatementStatus::Processing,
            'canDownload' => $user->can('downloadFile', $statement),
            'canMatch' => $user->can('match', $statement),
            'matching' => $this->matching(),
            'tenantNames' => array_map(static fn ($tenant): string => $tenant->name, $tenants->findMany($tenantIds)),
        ]);
    }

    /** @return array{matched: int, waiting: int, high: int, open: int} the statement's credits by match state */
    private function matching(): array
    {
        $credits = BankStatementLine::query()->where('bank_statement_import_id', $this->statementId)->where('direction', LineDirection::Credit->value);

        return [
            'matched' => (clone $credits)->where('match_status', MatchStatus::Matched->value)->count(),
            'waiting' => (clone $credits)->where('match_status', MatchStatus::Suggested->value)->count(),
            'high' => (clone $credits)->where('match_status', MatchStatus::Suggested->value)->where('match_confidence', MatchConfidence::High->value)->count(),
            'open' => (clone $credits)->where('match_status', MatchStatus::Unmatched->value)->count(),
        ];
    }

    private function statement(): BankStatementImport
    {
        return BankStatementImport::query()->findOrFail($this->statementId);
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
