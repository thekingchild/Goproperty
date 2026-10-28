<?php

declare(strict_types=1);

namespace Modules\Tickets\Livewire;

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
use Modules\Properties\Contracts\PropertyDirectory;
use Modules\Tickets\Enums\TicketStatus;
use Modules\Tickets\Lists\TicketList;
use Modules\Tickets\Lists\TicketParties;
use Modules\Tickets\Models\Ticket;

/**
 * Maintenance tickets, /o/{organization}/tickets (P04-01, a basic list:
 * P04-03 builds the inbox by status on it): the sent tickets, paginated on
 * the server (25 a page), newest sent first, with a debounced search over
 * the number, the title and the tenant, and filters by status ("Open":
 * waiting for the organization) and building, all kept in the URL
 * (ARCHITECTURE.md § 13). Names of a page are read in one lookup each
 * (Lists\TicketParties).
 *
 * Members with `tickets.view` see it (TicketPolicy::viewAny); an agent
 * assigned to buildings sees those buildings' tickets only, and only those
 * buildings in the filter. The rows come from Lists\TicketList, which also
 * exports them (P02-07). Runs inside the `organization` middleware,
 * replayed for Livewire updates.
 */
#[Layout('layouts::app')]
#[Title('Tickets')]
final class TicketIndex extends Component
{
    use WithListExports, WithPagination;

    public const int PER_PAGE = 25;

    #[Locked]
    public int $organizationId;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** A status value, `open`, or empty for every sent ticket. */
    #[Url(except: '')]
    public string $status = '';

    /** A building id, or empty for every building. */
    #[Url(except: '')]
    public string $building = '';

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
        $this->authorize('viewAny', Ticket::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'building'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status', 'building');
        $this->resetPage();
    }

    public function render(OrganizationContext $context, TicketList $list, TicketParties $parties, PropertyDirectory $buildings): View
    {
        $user = $this->user();
        $filters = $list->filters($this->listExports()['tickets']);
        $tickets = $list->query($user, $filters)->paginate(self::PER_PAGE);

        return view('tickets::livewire.ticket-index', [
            'organization' => $context->get(),
            'tickets' => $tickets,
            'rows' => $parties->rows($tickets->getCollection()),
            'filtered' => $filters !== [],
            'canCreate' => $user->can('create', Ticket::class),
            'statuses' => TicketStatus::sent(),
            'buildings' => $buildings->activeOptions($user),
        ]);
    }

    /** The list on the page, with its filters (TicketList; the Export button). */
    protected function listExports(): array
    {
        return ['tickets' => [
            'search' => $this->search,
            'status' => $this->status,
            'building' => $this->building,
        ]];
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
