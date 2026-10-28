<?php

declare(strict_types=1);

use App\Enums\OrganizationPermission;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Models\User;
use App\Platform\Impersonation\ImpersonationMode;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Modules\Leasing\Leases\LeaseService;
use Modules\Tickets\Enums\TicketSource;
use Modules\Tickets\Livewire\LeaseTickets;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/ticket-world.php';

/*
 * P04-03 "ticket from lease context": a lease's page lists the lease's
 * tickets (Contracts\TicketService::forLease, narrowed) and offers "Raise a
 * ticket", which opens the staff form on the lease's unit and tenant
 * (`?lease=`). The panel comes from the Tickets module through Leasing's
 * Contracts\LeasePagePanels; it shows the list only with `tickets.view`,
 * the button only with `tickets.create` on a live lease, and nothing
 * without either.
 */

beforeEach(function () {
    ticketWorld();
});

afterEach(fn () => ticketsFreshRequest());

/** The lease's page, as the user. */
function leasePageOf(User $user, int $leaseId, string $slug = 'acme-homes'): TestResponse
{
    ticketsFreshRequest();

    return test()->actingAs($user)->get("/o/{$slug}/leases/{$leaseId}");
}

describe('acceptance: a ticket from the lease context', function () {
    it('lists the lease\'s own tickets on its page, newest first, and links each to its ticket page', function () {
        $first = tenantTicket($this->acme, $this->kemi, $this->lease, ['title' => 'Kemi sink leaking']);
        $second = raiseTicket($this->acme, $this->owner, $this->flat3b, $this->kemiAtAcme, ['title' => 'Kemi wardrobe door']);
        tenantTicket($this->acme, $this->tunde, $this->tundeLease, ['title' => 'Tunde socket sparks']);
        draftTicket($this->acme, $this->kemi, $this->lease, ['title' => 'Kemi draft about the door']);

        $page = leasePageOf($this->owner, $this->lease->id)->assertOk()
            ->assertSee('data-test="lease-tickets"', false)
            ->assertSee('Kemi wardrobe door')->assertSee('Kemi sink leaking')
            ->assertDontSee('Tunde socket sparks')->assertDontSee('Kemi draft about the door')
            ->assertSee(route('workspace.tickets.show', ['organization' => 'acme-homes', 'ticket' => $first->id]), false)
            ->assertSee(route('workspace.tickets.create', ['organization' => 'acme-homes', 'lease' => $this->lease->id]), false);

        expect(listedTickets($page))->toBe([$second->id, $first->id]);

        leasePageOf($this->owner, $this->tundeLease->id)->assertOk()->assertSee('Tunde socket sparks')->assertDontSee('Kemi sink leaking');
        leasePageOf($this->owner, $this->femiLease->id)->assertOk()->assertSee('No tickets for this lease yet.');
    });

    it('opens the staff form on the lease\'s building, unit and tenant, and the ticket it raises is the lease\'s', function () {
        ticketsPage($this->owner, "/create?lease={$this->lease->id}")->assertOk()->assertDontSee('autofocus', false);
        $form = ticketsSnapshot($this->owner, "/create?lease={$this->lease->id}");

        expect($form)->toContain('"propertyId":"'.$this->palm->id.'"')
            ->toContain('"unitId":"'.$this->flat3b->id.'"')
            ->toContain('"tenantId":"'.$this->kemiAtAcme->id.'"');

        ticketsUpdate($this->owner, $form, ticketInput(['title' => 'Raised from the lease']), [['save']])->assertOk();
        $ticket = ticketsOf($this->acme)->sole();

        expect($ticket->lease_id)->toBe($this->lease->id)
            ->and($ticket->tenant_profile_id)->toBe($this->kemiAtAcme->id)
            ->and($ticket->unit_id)->toBe($this->flat3b->id)
            ->and($ticket->source)->toBe(TicketSource::Staff);

        leasePageOf($this->owner, $this->lease->id)->assertSee('Raised from the lease');
    });

    it('prefills nothing from a lease the member may not see: another building\'s for a narrowed agent, another organization\'s', function () {
        expect(ticketsSnapshot($this->agent, "/create?lease={$this->femiLease->id}"))->toContain('"unitId":""')->toContain('"tenantId":""')
            ->and(ticketsSnapshot($this->owner, "/create?lease={$this->foreignLease->id}"))->toContain('"unitId":""')->toContain('"tenantId":""')
            ->and(ticketsSnapshot($this->agent, "/create?lease={$this->lease->id}"))->toContain('"unitId":"'.$this->flat3b->id.'"');
    });

    it('offers "Raise a ticket" only on a live lease: an ended lease keeps its list', function () {
        tenantTicket($this->acme, $this->tunde, $this->tundeLease, ['title' => 'Tunde socket sparks']);
        inOrganization($this->acme, fn () => app(LeaseService::class)->terminate($this->owner, $this->tundeLease, 'Moved out early'));

        leasePageOf($this->owner, $this->tundeLease->id)->assertOk()->assertSee('Tunde socket sparks')->assertDontSee('data-test="lease-raise-ticket"', false);
        leasePageOf($this->owner, $this->lease->id)->assertOk()->assertSee('data-test="lease-raise-ticket"', false);
    });
});

describe('acceptance: the lease\'s tickets are hidden without the permissions', function () {
    it('shows neither the list nor the button to a member without `tickets.view` and `tickets.create`, and only the list without `tickets.create`', function () {
        tenantTicket($this->acme, $this->kemi, $this->lease, ['title' => 'Kemi sink leaking']);
        $agentRole = Role::query()->where('name', OrganizationRole::Agent->value)->whereNull('organization_id')->sole();

        $agentRole->revokePermissionTo(OrganizationPermission::CreateTickets->value);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        leasePageOf($this->freeAgent, $this->lease->id)->assertOk()->assertSee('Kemi sink leaking')->assertDontSee('data-test="lease-raise-ticket"', false);

        $agentRole->revokePermissionTo(OrganizationPermission::ViewTickets->value);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        leasePageOf($this->freeAgent, $this->lease->id)->assertOk()->assertDontSee('data-test="lease-tickets"', false)->assertDontSee('Kemi sink leaking');

        // The owner's role is untouched.
        leasePageOf($this->owner, $this->lease->id)->assertOk()->assertSee('Kemi sink leaking')->assertSee('data-test="lease-raise-ticket"', false);
    });

    it('keeps the list and hides the button while the organization is suspended and in a read-only support session', function () {
        tenantTicket($this->acme, $this->kemi, $this->lease, ['title' => 'Kemi sink leaking']);

        $this->acme->forceFill(['status' => OrganizationStatus::Suspended])->save();
        leasePageOf($this->owner, $this->lease->id)->assertOk()->assertSee('Kemi sink leaking')->assertDontSee('data-test="lease-raise-ticket"', false);
        $this->acme->forceFill(['status' => OrganizationStatus::Active])->save();

        ticketsFreshRequest();
        $staff = staffMember(['name' => 'Kemi Support', 'email' => 'kemi.support@goproperty.ng']);
        enterSupport(startSupport($staff, $this->owner, $this->acme, ImpersonationMode::ReadOnly))->assertRedirect('/o/acme-homes');
        asSupport()->get("/o/acme-homes/leases/{$this->lease->id}")->assertOk()->assertSee('Kemi sink leaking')->assertDontSee('data-test="lease-raise-ticket"', false);
    });

    it('shows a narrowed agent nothing of another building\'s lease, even when the panel is asked for it directly', function () {
        raiseTicket($this->acme, $this->owner, $this->oakUnit, $this->femi, ['title' => 'Oak borehole pump down']);

        leasePageOf($this->agent, $this->femiLease->id)->assertNotFound();

        ticketsFreshRequest();
        $html = inOrganization($this->acme, fn () => Livewire::actingAs($this->agent)->test(LeaseTickets::class, ['leaseId' => $this->femiLease->id])->html());
        expect($html)->not->toContain('data-test="lease-tickets"')->not->toContain('Oak borehole pump down');

        $owners = inOrganization($this->acme, fn () => Livewire::actingAs($this->owner)->test(LeaseTickets::class, ['leaseId' => $this->femiLease->id])->html());
        expect($owners)->toContain('Oak borehole pump down');

        // Another organization's lease id is simply not found.
        $foreign = inOrganization($this->acme, fn () => Livewire::actingAs($this->owner)->test(LeaseTickets::class, ['leaseId' => $this->foreignLease->id])->html());
        expect($foreign)->not->toContain('data-test="lease-tickets"');
    });
});
