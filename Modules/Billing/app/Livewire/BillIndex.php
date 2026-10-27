<?php

declare(strict_types=1);

namespace Modules\Billing\Livewire;

use App\Exports\Livewire\WithListExports;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Billing\Enums\BillStatus;
use Modules\Billing\Enums\BillType;
use Modules\Billing\Lists\BillList;
use Modules\Billing\Lists\BillParties;
use Modules\Billing\Models\Bill;
use Modules\Properties\Contracts\PropertyDirectory;

/**
 * Bills, /o/{organization}/bills (DATABASE_SCHEMA.md § 4; P03-04b),
 * paginated on the server (25 a page), with a debounced search over the
 * bill number, the payment reference and the tenant, and filters by status
 * (OVERDUE included), type and building, plus a lease or a tenant (from
 * those records' pages), all kept in the URL (ARCHITECTURE.md § 13). The
 * tenant, unit and building names of a page are read in one lookup each
 * (Lists\BillParties, no N+1).
 *
 * Members with `bills.view` see it (BillPolicy::viewAny); an agent
 * assigned to buildings sees those buildings' bills only, and only those
 * buildings in the filter. The rows come from Lists\BillList, which also
 * exports them (P02-07, Export button). Runs inside the `organization`
 * middleware, replayed for Livewire updates.
 */
#[Layout('layouts::app')]
#[Title('Bills')]
final class BillIndex extends Component
{
    use WithListExports, WithPagination;

    public const int PER_PAGE = 25;

    #[Locked]
    public int $organizationId;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $type = '';

    /** A building id, or empty for every building. */
    #[Url(except: '')]
    public string $building = '';

    /** A lease id (the lease page's "Bills" link), or empty. */
    #[Url(except: '')]
    public string $lease = '';

    /** A tenant id, or empty. */
    #[Url(except: '')]
    public string $tenant = '';

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
        $this->authorize('viewAny', Bill::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'type', 'building', 'lease', 'tenant'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status', 'type', 'building', 'lease', 'tenant');
        $this->resetPage();
    }

    public function render(OrganizationContext $context, BillList $list, BillParties $parties, PropertyDirectory $buildings): View
    {
        $user = $this->user();
        $filters = $list->filters($this->listExports()['bills']);
        $bills = $list->query($user, $filters)->paginate(self::PER_PAGE);

        return view('billing::livewire.bill-index', [
            'organization' => $context->get(),
            'bills' => $bills,
            'rows' => $parties->rows($bills->getCollection()),
            'filtered' => $filters !== [],
            'statuses' => BillStatus::cases(),
            'types' => BillType::cases(),
            'buildings' => $buildings->activeOptions($user),
        ]);
    }

    /** The list on the page, with its filters (BillList; the Export button). */
    protected function listExports(): array
    {
        return ['bills' => [
            'search' => $this->search,
            'status' => $this->status,
            'type' => $this->type,
            'building' => $this->building,
            'lease' => $this->lease,
            'tenant' => $this->tenant,
        ]];
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
