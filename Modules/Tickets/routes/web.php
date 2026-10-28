<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Tickets\Http\Controllers\TicketFileController;
use Modules\Tickets\Livewire\Owner\OwnerTicketIndex;
use Modules\Tickets\Livewire\Owner\OwnerTicketShow;
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

/*
 * The thread's files (P04-02; evidence): a signed link after the policy
 * check, one route per surface. The file is found among the messages the
 * reader may see, so an internal note's file answers 404 in the portals.
 * Refused while GoProperty staff impersonate (TicketFileController).
 */
Route::middleware(['auth', 'verified', 'organization', 'throttle:120,1'])->prefix('o/{organization}')->name('workspace.')->group(function () {
    Route::get('tickets/{ticket}/files/{media}', [TicketFileController::class, 'workspace'])->whereNumber(['ticket', 'media'])->name('tickets.files.show');
    Route::get('tickets/{ticket}/files/{media}/thumb', [TicketFileController::class, 'workspaceThumb'])->whereNumber(['ticket', 'media'])->name('tickets.files.thumb');
});

Route::middleware(['auth', 'verified', 'throttle:120,1'])->group(function () {
    Route::get('portal/tickets/{ticket}/files/{media}', [TicketFileController::class, 'portal'])->whereNumber(['ticket', 'media'])->name('portal.tickets.files.show');
    Route::get('portal/tickets/{ticket}/files/{media}/thumb', [TicketFileController::class, 'portalThumb'])->whereNumber(['ticket', 'media'])->name('portal.tickets.files.thumb');
    Route::get('owner/tickets/{ticket}/files/{media}', [TicketFileController::class, 'owner'])->whereNumber(['ticket', 'media'])->name('owner.tickets.files.show');
    Route::get('owner/tickets/{ticket}/files/{media}/thumb', [TicketFileController::class, 'ownerThumb'])->whereNumber(['ticket', 'media'])->name('owner.tickets.files.thumb');
});

/*
 * The building owner's tickets in the owner portal (P04-02; ARCHITECTURE.md
 * § 5 "respond to tickets if no agent assigned"): the list and a ticket,
 * across every organization that manages buildings of the user, through
 * Owner\OwnerTickets (Properties' OwnerPortalAccess). Both pages are
 * read-only (Properties' ReadOnlyOwnerPage); the reply form is its own
 * component (Livewire\Owner\OwnerTicketConversation), whose writer refuses
 * a reply once an agent is assigned.
 */
Route::middleware(['auth', 'verified'])->prefix('owner')->name('owner.')->group(function () {
    Route::livewire('tickets', OwnerTicketIndex::class)->name('tickets.index');
    Route::livewire('tickets/{ticket}', OwnerTicketShow::class)->whereNumber('ticket')->name('tickets.show');
});
