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
 * Maintenance tickets, /o/{organization}/tickets: the staff inbox (P04-01's
 * list, made an inbox by status in P04-03). The sent tickets, paginated on
 * the server (25 a page), newest sent first, with tabs by status (Open —
 * waiting for the organization —, Submitted, Under review, In progress,
 * Reopened, Resolved, Closed, All) and by assignee (Anyone, Assigned to
 * me, Unassigned), each with its count under the other filters, a
 * debounced search over the number, the title and the tenant, and a
 * building filter, all kept in the URL (ARCHITECTURE.md § 13). Names of a
 * page are read in one lookup each (Lists\TicketParties).
 *
 * Members with `tickets.view` see it (TicketPolicy::viewAny); an agent
 * assigned to buildings sees those buildings' tickets only, and only those
 * buildings in the filter and the counts. The rows and the counts come
 * from Lists\TicketList, which also exports the rows with the same
 * filters (P02-07). Runs inside the `organization` middleware,
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

    /** `me`, `none` (unassigned), or empty for anyone's. */
    #[Url(except: '')]
    public string $assignee = '';

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
        if (in_array($property, ['search', 'status', 'assignee', 'building'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status', 'assignee', 'building');
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
            'statusCounts' => $list->statusCounts($user, $filters),
            'assigneeCounts' => $list->assigneeCounts($user, $filters),
            'canCreate' => $user->can('create', Ticket::class),
            // The tabs in the order work goes: waiting, being handled, done.
            'statuses' => [TicketStatus::Submitted, TicketStatus::UnderReview, TicketStatus::InProgress, TicketStatus::Reopened, TicketStatus::Resolved, TicketStatus::Closed],
            'buildings' => $buildings->activeOptions($user),
        ]);
    }

    /** The list on the page, with its filters (TicketList; the Export button). */
    protected function listExports(): array
    {
        return ['tickets' => [
            'search' => $this->search,
            'status' => $this->status,
            'assignee' => $this->assignee,
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
