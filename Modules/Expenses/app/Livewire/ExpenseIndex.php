<?php

declare(strict_types=1);

namespace Modules\Expenses\Livewire;

use App\Exports\Livewire\WithListExports;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Expenses\Enums\ExpenseCategory;
use Modules\Expenses\Lists\ExpenseList;
use Modules\Expenses\Lists\ExpenseRows;
use Modules\Expenses\Models\Expense;
use Modules\Properties\Contracts\PropertyDirectory;

/**
 * Expenses, /o/{organization}/expenses (P03-12), paginated on the server
 * (25 a page), latest date paid first, with a debounced search over what
 * was paid for, who was paid and the reference, filters by category,
 * building (or the organization's own, no building), a date range and
 * Active / Archived, all kept in the URL (ARCHITECTURE.md § 13), and the
 * total of every expense the filters keep.
 *
 * Members with `expenses.view` see it (ExpensePolicy::viewAny); an agent
 * assigned to buildings sees those buildings' expenses only, and agents
 * never see the organization's own. The rows come from Lists\ExpenseList,
 * which also exports them (P02-07, Export button). Runs inside the
 * `organization` middleware, replayed for Livewire updates.
 */
#[Layout('layouts::app')]
#[Title('Expenses')]
final class ExpenseIndex extends Component
{
    use WithListExports, WithPagination;

    public const int PER_PAGE = 25;

    #[Locked]
    public int $organizationId;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $category = '';

    /** A building id, `none` for the organization's own, or empty for all. */
    #[Url(except: '')]
    public string $building = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    #[Url(except: false)]
    public bool $archived = false;

    public function boot(OrganizationContext $context): void
    {
        abort_unless($context->has(), 404);
    }

    public function hydrate(OrganizationContext $context): void
    {
        abort_unless($context->id() === $this->organizationId, 404);
    }

    public function mount(OrganizationContext $context): void
    {
        $this->organizationId = $context->id();
        $this->authorize('viewAny', Expense::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'category', 'building', 'from', 'to', 'archived'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'category', 'building', 'from', 'to');
        $this->resetPage();
    }

    public function render(OrganizationContext $context, ExpenseList $list, ExpenseRows $rows, PropertyDirectory $buildings): View
    {
        $user = $this->user();
        $filters = $list->filters($this->listExports()['expenses']);
        $query = $list->query($user, $filters);
        $expenses = (clone $query)->paginate(self::PER_PAGE);

        return view('expenses::livewire.expense-index', [
            'organization' => $context->get(),
            'expenses' => $expenses,
            'rows' => $rows->rows($expenses->getCollection()),
            'total' => Money::kobo((int) $query->toBase()->reorder()->sum('amount_kobo')),
            'filtered' => array_diff_key($filters, ['archived' => true]) !== [],
            'canCreate' => $user->can('create', Expense::class),
            'seesOrganization' => $user->can('viewOrganizationExpenses', Expense::class),
            'categories' => ExpenseCategory::cases(),
            'buildings' => $buildings->activeOptions($user),
        ]);
    }

    /** The list on the page, with its filters (ExpenseList; the Export button). */
    protected function listExports(): array
    {
        return ['expenses' => [
            'search' => $this->search,
            'category' => $this->category,
            'building' => $this->building,
            'from' => $this->from,
            'to' => $this->to,
            'archived' => $this->archived,
        ]];
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
