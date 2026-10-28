<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Modules\Leasing\Models\Lease;
use Modules\Properties\Models\Unit;
use Modules\Tenants\Models\TenantProfile;
use Modules\Tickets\Models\Ticket;
use Modules\Tickets\Models\TicketStatusChange;
use Modules\Tickets\Tickets\TicketService;
use Tests\Support\LivewireHttp;

/*
 * Helpers for the Tickets module's tests (P04-01). Tickets are made
 * through the real writer (Tickets\TicketService), pages through HTTP and
 * Livewire updates. Buildings, units, tenants and leases come from the
 * other modules' helpers.
 */

require_once __DIR__.'/../../../Leasing/tests/Feature/helpers.php';

/** The ticket form's fields, with changes. */
function ticketInput(array $changes = []): array
{
    return $changes + [
        'category' => 'plumbing',
        'priority' => 'high',
        'title' => 'Kitchen sink leaking',
        'description' => 'Water drips under the kitchen sink and the cabinet floor is wet.',
    ];
}

/** A ticket a member raises for the unit (and its tenant, when given), through TicketService. */
function raiseTicket(Organization $organization, User $by, Unit $unit, ?TenantProfile $tenant = null, array $changes = []): Ticket
{
    return inOrganization($organization, fn (): Ticket => app(TicketService::class)->createOnBehalf($by, ['unitId' => $unit->id, 'tenantId' => $tenant?->id] + ticketInput($changes)));
}

/** A tenant's draft for the home of their lease, from the portal, through TicketService. */
function draftTicket(Organization $organization, User $tenant, Lease $lease, array $changes = []): Ticket
{
    return inOrganization($organization, fn (): Ticket => app(TicketService::class)->draftAsTenant($tenant, $lease->id, ticketInput($changes)));
}

/** A tenant's ticket, drafted and sent. */
function tenantTicket(Organization $organization, User $tenant, Lease $lease, array $changes = []): Ticket
{
    $draft = draftTicket($organization, $tenant, $lease, $changes);

    return inOrganization($organization, fn (): Ticket => app(TicketService::class)->submit($tenant, $draft));
}

/** Runs a TicketService method as $by in the organization. */
function ticketsDo(Organization $organization, Closure $callback): mixed
{
    return inOrganization($organization, fn (): mixed => $callback(app(TicketService::class)));
}

/** The ticket as stored now (discarded drafts too). */
function storedTicket(Organization $organization, Ticket $ticket): Ticket
{
    return inOrganization($organization, fn (): Ticket => Ticket::query()->withTrashed()->findOrFail($ticket->id));
}

/** Every ticket of the organization, oldest first (discarded drafts too). */
function ticketsOf(Organization $organization): Collection
{
    return inOrganization($organization, fn () => Ticket::query()->withTrashed()->orderBy('id')->get());
}

/** The ticket's status history as [from, to, changed by, note], oldest first. */
function ticketHistory(Organization $organization, Ticket $ticket): array
{
    return inOrganization($organization, fn (): array => TicketStatusChange::query()->where('ticket_id', $ticket->id)->orderBy('id')->get()
        ->map(fn (TicketStatusChange $change): array => [$change->from_status?->value, $change->to_status->value, $change->changed_by_user_id, $change->note])->all());
}

/** Forces the ticket into a status, for tests that start from one (the writer is what is under test). */
function forceTicketStatus(Organization $organization, Ticket $ticket, string $status, array $attributes = []): Ticket
{
    return inOrganization($organization, function () use ($ticket, $status, $attributes): Ticket {
        $stored = Ticket::query()->withTrashed()->findOrFail($ticket->id);
        $stored->forceFill(['status' => $status] + $attributes)->saveQuietly();

        return $stored->fresh() ?? $stored;
    });
}

/** A fresh request, as production starts every one. */
function ticketsFreshRequest(): void
{
    leasesFreshRequest();
}

/** A page of the tickets area, as the user ($path after /tickets). */
function ticketsPage(User $user, string $path = '', string $slug = 'acme-homes'): TestResponse
{
    ticketsFreshRequest();

    return test()->actingAs($user)->get("/o/{$slug}/tickets{$path}");
}

/** The Livewire snapshot of a tickets page the user can open. */
function ticketsSnapshot(User $user, string $path = '', string $slug = 'acme-homes'): string
{
    $snapshot = LivewireHttp::snapshot(ticketsPage($user, $path, $slug)->assertOk());
    ticketsFreshRequest();
    test()->actingAs($user);

    return $snapshot;
}

/** A Livewire update on a snapshot, as a fresh request by the same user. */
function ticketsUpdate(User $user, string $snapshot, array $updates = [], array $calls = []): TestResponse
{
    ticketsFreshRequest();
    test()->actingAs($user);

    return LivewireHttp::update(test(), $snapshot, $updates, $calls);
}

/** A tenant portal page of the repairs ($path after /portal/tickets). */
function repairsPage(User $user, string $path = ''): TestResponse
{
    ticketsFreshRequest();

    return test()->actingAs($user)->get('/portal/tickets'.$path);
}

/** The Livewire snapshot of a repairs page the user can open. */
function repairsSnapshot(User $user, string $path = ''): string
{
    $snapshot = LivewireHttp::snapshot(repairsPage($user, $path)->assertOk());
    ticketsFreshRequest();
    test()->actingAs($user);

    return $snapshot;
}

/** The ticket ids a rendered page lists (data-ticket), in order, without repeats. */
function listedTickets(TestResponse|string $page): array
{
    preg_match_all('/data-ticket="(\d+)"/', htmlOf($page), $matches);

    return array_values(array_unique(array_map('intval', $matches[1])));
}
