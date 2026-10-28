<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use Modules\Leasing\Contracts\LeasePagePanels;
use Modules\Leasing\Contracts\UnitLeases;
use Modules\Leasing\Leases\LeasePanelRegistry;
use Modules\Leasing\Leases\LeaseService;

require_once __DIR__.'/helpers.php';

/*
 * P04-03: other modules add panels to a lease's page through
 * Contracts\LeasePagePanels (the Tickets module: the lease's tickets and
 * "Raise a ticket"), and find one lease through Contracts\UnitLeases::lease
 * (the ticket form opened from a lease). The Leasing module only places
 * the panels; with none registered (a module switched off) the page shows
 * none.
 */

beforeEach(function () {
    seedOrganizationRoles();
    $this->travelTo('2026-12-20 10:00:00');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $this->other = Organization::factory()->create(['slug' => 'other-realty']);
    $this->owner = joinAs(User::factory()->create(), $this->acme);
    $this->flat = unitIn($this->acme, buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court']), ['unit_label' => 'Flat 1']);
    $this->kemi = tenantIn($this->acme, ['first_name' => 'Kemi', 'last_name' => 'Adebayo', 'phone' => '+2348031234567']);
    $this->lease = activeLease($this->acme, $this->owner, $this->flat, $this->kemi);
});

afterEach(fn () => leasesFreshRequest());

it('places each registered panel below the lease, once, mounted with the lease', function () {
    expect(app(LeasePagePanels::class)->all())->toBe(['tickets::lease-tickets']);

    app(LeasePagePanels::class)->register('tickets::lease-tickets');
    expect(app(LeasePagePanels::class)->all())->toBe(['tickets::lease-tickets']);

    $page = leasesPage($this->owner, "/{$this->lease->id}")->assertOk();
    expect(substr_count((string) $page->getContent(), 'data-test="lease-tickets"'))->toBe(1)
        ->and((string) $page->getContent())->toContain('lease='.$this->lease->id);
});

it('shows no panel when no module registers one', function () {
    app()->instance(LeasePagePanels::class, new LeasePanelRegistry);

    leasesPage($this->owner, "/{$this->lease->id}")->assertOk()->assertSee('Kemi Adebayo')->assertDontSee('data-test="lease-tickets"', false);
});

it('finds one lease of the organization, whatever its status, for other modules', function () {
    $found = inOrganization($this->acme, fn () => app(UnitLeases::class)->lease($this->lease->id));
    inOrganization($this->acme, fn () => app(LeaseService::class)->terminate($this->owner, $this->lease, 'Moved out'));
    $ended = inOrganization($this->acme, fn () => app(UnitLeases::class)->lease($this->lease->id));

    expect($found?->unitId)->toBe($this->flat->id)
        ->and($found?->tenantProfileId)->toBe($this->kemi->id)
        ->and($found?->status)->toBe('active')
        ->and($ended?->status)->toBe('terminated')
        ->and(inOrganization($this->other, fn () => app(UnitLeases::class)->lease($this->lease->id)))->toBeNull();
});
