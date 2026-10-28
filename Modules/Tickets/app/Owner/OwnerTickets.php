<?php

declare(strict_types=1);

namespace Modules\Tickets\Owner;

use App\Branding\BrandColours;
use App\Branding\Branding;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Closure;
use Illuminate\Support\Facades\Gate;
use Modules\OwnerStatements\Contracts\TenantContactSharing;
use Modules\Properties\Contracts\OwnerPortalAccess;
use Modules\Properties\Contracts\OwnerProfileDirectory;
use Modules\Properties\Contracts\PortalOwner;
use Modules\Properties\Contracts\PropertyDirectory;
use Modules\Properties\Contracts\PropertySummary;
use Modules\Tickets\Enums\TicketStatus;
use Modules\Tickets\Lists\TicketParties;
use Modules\Tickets\Models\Ticket;

/**
 * The owner portal's tickets (`/owner/tickets`, P04-02), across every
 * organization that manages buildings of the user, through Properties'
 * OwnerPortalAccess (the "linked to my user" path: each owner profile
 * allowed by OwnerProfilePolicy::viewAsOwner; while GoProperty staff
 * impersonate, only the session's organization) and OwnerProfileDirectory
 * (the profile's buildings). Inside each organization's context with the
 * scope in place: the sent tickets of those buildings (a tenant's draft
 * never). A ticket id that is not of the user's buildings is simply not
 * found (404); TicketPolicy::viewAsOwner checks it again.
 */
final readonly class OwnerTickets
{
    /** At most this many tickets an agency shows on the list (newest first). */
    public const int LIMIT = 100;

    public function __construct(
        private OwnerPortalAccess $portal,
        private OwnerProfileDirectory $profiles,
        private PropertyDirectory $buildings,
        private OrganizationContext $context,
        private TicketParties $parties,
        private TenantContactSharing $sharing,
    ) {}

    /** @return list<OwnerAgencyTickets> by organization name, one per owner profile */
    public function agencies(User $user): array
    {
        $agencies = [];

        foreach ($this->portal->owners($user) as $owner) {
            $agencies[] = $this->portal->within($user, $owner->ownerProfileId, function (PortalOwner $owner): OwnerAgencyTickets {
                $organization = $this->context->get();
                $branding = Branding::fromOrganization($organization);
                $tickets = Ticket::query()->whereIn('property_id', $this->buildingIds($owner->ownerProfileId))
                    ->where('status', '!=', TicketStatus::Draft)
                    ->orderByDesc('submitted_at')->orderByDesc('id')
                    ->limit(self::LIMIT)->get();

                return new OwnerAgencyTickets(
                    organizationId: $organization->id,
                    ownerProfileId: $owner->ownerProfileId,
                    name: $branding->displayName(),
                    colour: BrandColours::textShade($branding->primaryColor),
                    initials: $branding->initials(),
                    tickets: $this->parties->rows($tickets->all()),
                );
            });
        }

        return array_values(array_filter($agencies));
    }

    /**
     * The ticket $ticketId of one of the user's buildings, with the owner
     * profile it is found through, or null (the page answers 404).
     *
     * @return array{PortalOwner, Ticket}|null
     */
    public function find(User $user, int $ticketId): ?array
    {
        foreach ($this->portal->owners($user) as $owner) {
            $ticket = $this->portal->within($user, $owner->ownerProfileId, function (PortalOwner $owner) use ($user, $ticketId): ?Ticket {
                $ticket = Ticket::query()->whereKey($ticketId)->where('status', '!=', TicketStatus::Draft)
                    ->whereIn('property_id', $this->buildingIds($owner->ownerProfileId))->first();

                return $ticket instanceof Ticket && Gate::forUser($user)->allows('viewAsOwner', $ticket) ? $ticket : null;
            });

            if ($ticket instanceof Ticket) {
                return [$owner, $ticket];
            }
        }

        return null;
    }

    /**
     * $callback inside the organization of the owner profile.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T|null null when the profile is no longer the user's (404)
     */
    public function within(User $user, PortalOwner $owner, Closure $callback): mixed
    {
        return $this->portal->within($user, $owner->ownerProfileId, static fn (): mixed => $callback());
    }

    /**
     * The user's owner profile here that owns the ticket's building, or
     * null. Inside the ticket's organization (TicketPolicy's owner
     * abilities).
     */
    public function ownerProfileFor(User $user, Ticket $ticket): ?int
    {
        $building = $this->buildings->find($ticket->property_id);

        if ($building === null || $building->ownerProfileId === null || ! $this->context->has()) {
            return null;
        }

        foreach ($this->portal->owners($user) as $owner) {
            if ($owner->organizationId === $this->context->id() && $owner->ownerProfileId === $building->ownerProfileId) {
                return $owner->ownerProfileId;
            }
        }

        return null;
    }

    /** Whether the agency shares tenants' phone and email with this owner (OwnerStatements' switch, P08-05). Inside the organization. */
    public function sharesTenantContacts(int $ownerProfileId): bool
    {
        return $this->sharing->sharesWith($ownerProfileId);
    }

    /** @return list<int> */
    private function buildingIds(int $ownerProfileId): array
    {
        return array_map(static fn (PropertySummary $building): int => $building->id, $this->profiles->buildingsOf($ownerProfileId));
    }
}
