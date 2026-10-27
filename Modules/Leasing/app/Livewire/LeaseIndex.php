<?php

declare(strict_types=1);

namespace Modules\Leasing\Livewire;

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
use Modules\Leasing\Contracts\BillingCycle;
use Modules\Leasing\Enums\LeaseStatus;
use Modules\Leasing\Lists\LeaseList;
use Modules\Leasing\Lists\LeaseParties;
use Modules\Leasing\Models\Lease;
use Modules\Properties\Contracts\PropertyDirectory;

/**
 * Leases, /o/{organization}/leases (DATABASE_SCHEMA.md § 3; P03-03a),
 * paginated on the server (25 a page), with a debounced search over the
 * contract number and the tenant, and filters by status, building, billing
 * cycle and "ending soon", all kept in the URL (ARCHITECTURE.md § 13). The
 * tenant, unit and building names of a page are read in one lookup each
 * (Lists\LeaseParties, no N+1).
 *
 * Members with `leases.view` see it (LeasePolicy::viewAny); an agent
 * assigned to buildings sees those buildings' leases only, and only those
 * buildings in the filter. The rows come from Lists\LeaseList, which also
 * exports them (P02-07, Export button). Runs inside the `organization`
 * middleware, replayed for Livewire updates.
 */
#[Layout('layouts::app')]
#[Title('Leases')]
final class LeaseIndex extends Component
{
    use WithListExports, WithPagination;

    public const int PER_PAGE = 25;

    #[Locked]
    public int $organizationId;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    /** A building id, or empty for every building. */
    #[Url(except: '')]
    public string $building = '';

    #[Url(except: '')]
    public string $cycle = '';

    #[Url(except: false)]
    public bool $ending = false;

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
        $this->authorize('viewAny', Lease::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'building', 'cycle', 'ending'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status', 'building', 'cycle', 'ending');
        $this->resetPage();
    }

    public function render(OrganizationContext $context, LeaseList $list, LeaseParties $parties, PropertyDirectory $buildings): View
    {
        $user = $this->user();
        $filters = $list->filters($this->listExports()['leases']);
        $leases = $list->query($user, $filters)->paginate(self::PER_PAGE);

        return view('leasing::livewire.lease-index', [
            'organization' => $context->get(),
            'leases' => $leases,
            'rows' => $parties->rows($leases->getCollection()),
            'filtered' => $filters !== [],
            'canCreate' => $user->can('create', Lease::class),
            'statuses' => LeaseStatus::cases(),
            'cycles' => BillingCycle::cases(),
            'buildings' => $buildings->activeOptions($user),
        ]);
    }

    /** The list on the page, with its filters (LeaseList; the Export button). */
    protected function listExports(): array
    {
        return ['leases' => [
            'search' => $this->search,
            'status' => $this->status,
            'building' => $this->building,
            'cycle' => $this->cycle,
            'ending' => $this->ending,
        ]];
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
