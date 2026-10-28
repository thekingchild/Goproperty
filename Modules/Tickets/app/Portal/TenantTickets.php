<?php

declare(strict_types=1);

namespace Modules\Tickets\Portal;

use App\Branding\BrandColours;
use App\Branding\Branding;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Closure;
use Illuminate\Support\Facades\Gate;
use Modules\Leasing\Contracts\TenantLeases;
use Modules\Properties\Contracts\PropertyDirectory;
use Modules\Properties\Contracts\UnitDirectory;
use Modules\Tenants\Contracts\PortalTenant;
use Modules\Tenants\Contracts\TenantPortalAccess;
use Modules\Tickets\Lists\TicketParties;
use Modules\Tickets\Models\Ticket;

/**
 * The tenant portal's tickets (P04-01), across every organization the user
 * rents from, through the Tenants module's portal path
 * (Contracts\TenantPortalAccess: the user's own tenant records, each
 * allowed by viewAsTenant; while staff impersonate, only the session's
 * organization). Everything is read inside each organization's context
 * with the scope in place; a ticket is the user's when its tenant record
 * is one of theirs there, and TicketPolicy::viewAsTenant checks it again.
 * A ticket id that is not the user's own is simply not found (404).
 */
final readonly class TenantTickets
{
    /** At most this many tickets an agency shows on the page (newest first). */
    public const int LIMIT = 100;

    public function __construct(
        private TenantPortalAccess $portal,
        private OrganizationContext $context,
        private TenantLeases $leases,
        private UnitDirectory $units,
        private PropertyDirectory $buildings,
        private TicketParties $parties,
    ) {}

    /** @return list<AgencyTickets> by agency name */
    public function agencies(User $user): array
    {
        return array_map(fn (PortalTenant $tenancy): AgencyTickets => $this->context->run($tenancy->organization, function () use ($user, $tenancy): AgencyTickets {
            $branding = Branding::fromOrganization($tenancy->organization);
            $tickets = Ticket::query()->whereIn('tenant_profile_id', $tenancy->tenantProfileIds)
                ->orderByRaw('coalesce(submitted_at, created_at) desc')->orderByDesc('id')
                ->limit(self::LIMIT)->get()
                ->filter(static fn (Ticket $ticket): bool => Gate::forUser($user)->allows('viewAsTenant', $ticket));

            return new AgencyTickets(
                organizationId: $tenancy->organization->id,
                name: $branding->displayName(),
                colour: BrandColours::textShade($branding->primaryColor),
                initials: $branding->initials(),
                readOnly: $tenancy->readOnly(),
                canRaise: Gate::forUser($user)->allows('createAsTenant', Ticket::class) && $this->homesHere($tenancy) !== [],
                tickets: $this->parties->rows($tickets->values()->all()),
            );
        }), $this->portal->tenancies($user));
    }

    /**
     * The homes the user may report a problem for now, in every agency (or
     * only $organizationId's).
     *
     * @return list<PortalHome>
     */
    public function homes(User $user, ?int $organizationId = null): array
    {
        $homes = [];

        foreach ($this->portal->tenancies($user) as $tenancy) {
            if ($organizationId !== null && $tenancy->organization->id !== $organizationId) {
                continue;
            }

            $homes = [...$homes, ...$this->context->run($tenancy->organization, fn (): array => Gate::forUser($user)->allows('createAsTenant', Ticket::class) ? $this->homesHere($tenancy) : [])];
        }

        return $homes;
    }

    /**
     * The user's ticket $ticketId with the organization it is in, or null
     * (the page answers 404).
     *
     * @return array{PortalTenant, Ticket}|null
     */
    public function find(User $user, int $ticketId): ?array
    {
        foreach ($this->portal->tenancies($user) as $tenancy) {
            $ticket = $this->context->run($tenancy->organization, static function () use ($user, $tenancy, $ticketId): ?Ticket {
                $ticket = Ticket::query()->whereKey($ticketId)->whereIn('tenant_profile_id', $tenancy->tenantProfileIds)->first();

                return $ticket instanceof Ticket && Gate::forUser($user)->allows('viewAsTenant', $ticket) ? $ticket : null;
            });

            if ($ticket !== null) {
                return [$tenancy, $ticket];
            }
        }

        return null;
    }

    /** The tenancy of the user in $organizationId, or null. */
    public function tenancy(User $user, int $organizationId): ?PortalTenant
    {
        foreach ($this->portal->tenancies($user) as $tenancy) {
            if ($tenancy->organization->id === $organizationId) {
                return $tenancy;
            }
        }

        return null;
    }

    /**
     * $callback inside the tenancy's organization.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function within(PortalTenant $tenancy, Closure $callback): mixed
    {
        return $this->context->run($tenancy->organization, $callback);
    }

    /**
     * The live (draft or active) leases of the user's records here whose
     * unit still exists, as homes. Inside the tenancy's organization.
     *
     * @return list<PortalHome>
     */
    private function homesHere(PortalTenant $tenancy): array
    {
        $leases = [];

        foreach ($tenancy->tenantProfileIds as $profileId) {
            foreach ($this->leases->forTenant($profileId) as $lease) {
                if (in_array($lease->status, ['draft', 'active'], true)) {
                    $leases[] = $lease;
                }
            }
        }

        if ($leases === []) {
            return [];
        }

        $units = $this->units->findMany(array_values(array_unique(array_map(static fn ($lease): int => $lease->unitId, $leases))));
        $buildings = $this->buildings->findMany(array_values(array_unique(array_map(static fn ($lease): int => $lease->propertyId, $leases))));
        $agency = Branding::fromOrganization($tenancy->organization)->displayName();
        $homes = [];

        foreach ($leases as $lease) {
            if (! isset($units[$lease->unitId])) {
                continue;
            }

            $homes[] = new PortalHome(
                key: $tenancy->organization->id.'-'.$lease->id,
                organizationId: $tenancy->organization->id,
                leaseId: $lease->id,
                unit: $units[$lease->unitId]->label,
                building: isset($buildings[$lease->propertyId]) ? $buildings[$lease->propertyId]->name : $units[$lease->unitId]->propertyName,
                agency: $agency,
            );
        }

        return $homes;
    }
}
