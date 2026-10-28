<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Tickets\Livewire\Portal\PortalTicketForm;
use Modules\Tickets\Livewire\Portal\PortalTickets;
use Modules\Tickets\Livewire\Portal\PortalTicketShow;
use Modules\Tickets\Livewire\TicketForm;
use Modules\Tickets\Livewire\TicketIndex;
use Modules\Tickets\Livewire\TicketSettingsPage;
use Modules\Tickets\Livewire\TicketShow;

/*
 * Workspace pages of the Tickets module (P04-01). `organization` checks
 * membership (404 otherwise) and sets the organization context before
 * route model binding, so {ticket} resolves only inside the organization
 * (ARCHITECTURE.md § 4); TicketPolicy answers 404 for a tenant's draft and
 * outside a narrowed agent's buildings.
 */
Route::middleware(['auth', 'verified', 'organization'])->prefix('o/{organization}')->name('workspace.')->group(function () {
    Route::livewire('tickets', TicketIndex::class)->name('tickets.index');
    Route::livewire('tickets/create', TicketForm::class)->name('tickets.create');
    Route::livewire('tickets/{ticket}', TicketShow::class)->whereNumber('ticket')->name('tickets.show');

    // Settings → Maintenance tickets: the reopen window and auto-close days (org-owner, org-admin).
    Route::livewire('settings/operations/tickets', TicketSettingsPage::class)->name('settings.operations.tickets');
});

/*
 * The tenant portal's repairs (P04-01; the portal's rules, P03-11a): across
 * every organization the user rents from, so no `organization` middleware
 * and no organization in the URL. Every page reads through the Tenants
 * module's TenantPortalAccess (the "linked to my user" path) and checks
 * TicketPolicy::viewAsTenant for the ticket it shows; a ticket that is not
 * the user's own answers 404.
 */
Route::middleware(['auth', 'verified'])->prefix('portal')->name('portal.')->group(function () {
    Route::livewire('tickets', PortalTickets::class)->name('tickets.index');
    Route::livewire('tickets/new', PortalTicketForm::class)->middleware('throttle:60,1')->name('tickets.create');
    Route::livewire('tickets/{ticket}', PortalTicketShow::class)->whereNumber('ticket')->name('tickets.show');
    Route::livewire('tickets/{ticket}/edit', PortalTicketForm::class)->whereNumber('ticket')->middleware('throttle:60,1')->name('tickets.edit');
});
