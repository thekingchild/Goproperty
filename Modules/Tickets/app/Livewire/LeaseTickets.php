<?php

declare(strict_types=1);

namespace Modules\Tickets\Livewire;

use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Leasing\Contracts\LeaseSummary;
use Modules\Leasing\Contracts\UnitLeases;
use Modules\Properties\Contracts\BuildingAccess;
use Modules\Tickets\Contracts\TicketService;
use Modules\Tickets\Models\Ticket;

/**
 * A lease's maintenance tickets, on the lease's page (P04-03; added
 * through Leasing's Contracts\LeasePagePanels, so the Leasing module never
 * reads tickets): the newest tickets raised for the lease
 * (Contracts\TicketService::forLease, narrowed for the member) with a link
 * to each, and "Raise a ticket", which opens the staff form on the lease's
 * unit and tenant (`?lease=`). The list shows only with `tickets.view`,
 * the button only with `tickets.create` and while the lease is live (draft
 * or active: a ticket is about its tenant now); without either, nothing
 * shows. Read-only itself: the form and TicketService check everything
 * again. The lease id is locked; an agent never sees another building's.
 */
final class LeaseTickets extends Component
{
    /** At most this many tickets show on the lease's page (newest first). */
    public const int LIMIT = 10;

    #[Locked]
    public int $organizationId;

    #[Locked]
    public int $leaseId;

    public function boot(OrganizationContext $context): void
    {
        abort_unless($context->has(), 404);
    }

    public function hydrate(OrganizationContext $context): void
    {
        abort_unless($context->id() === $this->organizationId, 404);
    }

    public function mount(OrganizationContext $context, int $leaseId): void
    {
        $this->organizationId = $context->id();
        $this->leaseId = $leaseId;
    }

    public function render(OrganizationContext $context, TicketService $tickets, UnitLeases $leases, BuildingAccess $access): View
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $lease = $leases->lease($this->leaseId);
        $reachable = $lease instanceof LeaseSummary && $access->allows($user, $lease->propertyId);
        $canView = $reachable && $user->can('viewAny', Ticket::class);
        $all = $canView ? $tickets->forLease($this->leaseId, $user) : [];

        return view('tickets::livewire.lease-tickets', [
            'organization' => $context->get(),
            'canView' => $canView,
            'canCreate' => $reachable && in_array($lease->status, ['draft', 'active'], true) && $user->can('create', Ticket::class),
            'tickets' => array_slice($all, 0, self::LIMIT),
            'more' => max(0, count($all) - self::LIMIT),
        ]);
    }
}
