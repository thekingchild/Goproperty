<?php

declare(strict_types=1);

namespace Modules\Tickets\Livewire;

use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Leasing\Contracts\LeaseSummary;
use Modules\Leasing\Contracts\UnitLeases;
use Modules\Properties\Contracts\BuildingAccess;
use Modules\Properties\Contracts\PropertyDirectory;
use Modules\Properties\Contracts\UnitDirectory;
use Modules\Tenants\Contracts\TenantDirectory;
use Modules\Tickets\Enums\TicketCategory;
use Modules\Tickets\Enums\TicketPriority;
use Modules\Tickets\Models\Ticket;
use Modules\Tickets\Tickets\Assignees;
use Modules\Tickets\Tickets\TicketInput;
use Modules\Tickets\Tickets\TicketService;

/**
 * Raise a ticket, /o/{organization}/tickets/create (P04-01;
 * docs/plans/P04-subtasks.md default 3): a member reports a problem on a
 * tenant's behalf, or for a unit with no tenant (a common area kept as a
 * unit). The building picker offers the member's buildings only
 * (PropertyDirectory::activeOptions narrowed by BuildingAccess), the unit
 * picker that building's units, the tenant picker the tenants of the
 * unit's live leases (Leasing's UnitLeases), and "Assign to" the members
 * who work on the building (Tickets\Assignees). `?unit=` opens it on a unit
 * (from a unit's page), `?lease=` on a lease's unit and, while the lease is
 * live, its tenant (from the lease's page, P04-03; through Leasing's
 * UnitLeases). The ticket starts `submitted`, with its number;
 * TicketService checks everything again.
 */
#[Layout('layouts::app')]
#[Title('Raise a ticket')]
final class TicketForm extends Component
{
    #[Locked]
    public int $organizationId;

    public string $propertyId = '';

    #[Url(as: 'unit', except: '')]
    public string $unitId = '';

    /** The lease the form was opened from (`?lease=`): its unit and tenant to start with. */
    #[Url(as: 'lease', except: '')]
    public string $leaseId = '';

    public string $tenantId = '';

    public string $category = '';

    public string $priority = 'medium';

    public string $title = '';

    public string $description = '';

    public string $assigneeId = '';

    public function boot(OrganizationContext $context): void
    {
        abort_unless($context->has(), 404);
    }

    public function hydrate(OrganizationContext $context): void
    {
        abort_unless($context->id() === $this->organizationId, 404);
    }

    public function mount(OrganizationContext $context, UnitDirectory $units, BuildingAccess $access): void
    {
        $this->organizationId = $context->id();
        $this->authorize('create', Ticket::class);

        $lease = ctype_digit($this->leaseId) ? app(UnitLeases::class)->lease((int) $this->leaseId) : null;

        if ($lease !== null) {
            $this->unitId = (string) $lease->unitId;
        }

        $unit = ctype_digit($this->unitId) ? $units->find((int) $this->unitId) : null;

        if ($unit === null || $unit->propertyArchived || ! $access->allows($this->user(), $unit->propertyId)) {
            $this->unitId = '';

            return;
        }

        $this->propertyId = (string) $unit->propertyId;
        $live = $this->liveLeases((int) $this->unitId);
        // The lease's own tenant while it holds the unit; else whoever does now.
        $fromLease = $lease !== null ? array_values(array_filter($live, static fn (LeaseSummary $summary): bool => $summary->id === $lease->id))[0] ?? null : null;
        $holder = $fromLease ?? $live[0] ?? null;
        $this->tenantId = $holder !== null ? (string) $holder->tenantProfileId : '';
    }

    /** A building chosen: its unit, tenant and assignee are chosen again. */
    public function updatedPropertyId(): void
    {
        $this->reset('unitId', 'tenantId', 'assigneeId');
    }

    /** A unit chosen: its current tenant, when it has one, to start with. */
    public function updatedUnitId(): void
    {
        $this->tenantId = ctype_digit($this->unitId) ? (string) ($this->liveLeases((int) $this->unitId)[0]->tenantProfileId ?? '') : '';
    }

    public function save(TicketService $tickets): void
    {
        $this->authorize('create', Ticket::class);

        $ticket = $tickets->createOnBehalf($this->user(), [
            'unitId' => $this->unitId,
            'tenantId' => $this->tenantId,
            'category' => $this->category,
            'priority' => $this->priority,
            'title' => $this->title,
            'description' => $this->description,
            'assigneeId' => $this->assigneeId,
        ]);

        session()->flash('ticket-status', __('Ticket :number was raised.', ['number' => $ticket->number]));
        $this->redirectRoute('workspace.tickets.show', ['organization' => app(OrganizationContext::class)->get()->slug, 'ticket' => $ticket->id], navigate: true);
    }

    public function render(OrganizationContext $context, PropertyDirectory $buildings, UnitDirectory $units, BuildingAccess $access, TenantDirectory $tenants, Assignees $assignees): View
    {
        $user = $this->user();
        $building = ctype_digit($this->propertyId) && $access->allows($user, (int) $this->propertyId) ? (int) $this->propertyId : null;
        $leases = $building !== null && ctype_digit($this->unitId) ? $this->liveLeases((int) $this->unitId) : [];
        $names = $tenants->findMany(array_values(array_unique(array_map(static fn (LeaseSummary $lease): int => $lease->tenantProfileId, $leases))));
        $tenantOptions = [];

        foreach ($leases as $lease) {
            $tenantOptions[$lease->tenantProfileId] ??= ($names[$lease->tenantProfileId]->name ?? __('Unknown tenant')).($lease->status === 'draft' ? ' ('.__('moving in').')' : '');
        }

        return view('tickets::livewire.ticket-form', [
            'organization' => $context->get(),
            'buildings' => $buildings->activeOptions($user),
            'units' => $building !== null ? $units->inProperty($building) : [],
            'tenants' => $tenantOptions,
            'assignees' => $building !== null ? $assignees->for($building) : [],
            'categories' => TicketCategory::cases(),
            'priorities' => TicketPriority::cases(),
            'titleMax' => TicketInput::TITLE_MAX,
            'descriptionMax' => TicketInput::DESCRIPTION_MAX,
        ]);
    }

    /** @return list<LeaseSummary> the unit's draft and active leases, when the unit is in the member's buildings */
    private function liveLeases(int $unitId): array
    {
        $unit = app(UnitDirectory::class)->find($unitId);

        if ($unit === null || ! app(BuildingAccess::class)->allows($this->user(), $unit->propertyId)) {
            return [];
        }

        return app(UnitLeases::class)->live($unitId);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
