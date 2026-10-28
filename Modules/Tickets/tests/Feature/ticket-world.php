<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;

/*
 * The Tickets module's test world (P04-01). Fixed names, phones and emails
 * for everyone who must NOT show (orchestrator brief):
 *
 * Adeyemi Realty (acme-homes): Ada the owner, Seyi an administrator, Bola
 * an agent assigned to Palm Court only, Chika an agent with no assignment
 * (sees every building). Palm Court has Flat 3B (Kemi, portal account),
 * Flat 4C (Tunde, portal account) and the Gatehouse (no tenant); Oak Lodge
 * has Unit 1 (Femi, no portal account). Bello Homes (other-realty) has its
 * own owner and a tenant at Foreign Court.
 */

require_once __DIR__.'/helpers.php';

function ticketWorld(): void
{
    $test = test();
    (function () {
        seedOrganizationRoles();
        $this->travelTo('2027-03-15 10:00:00');

        $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
        $this->other = Organization::factory()->create(['slug' => 'other-realty', 'name' => 'Bello Homes Ltd']);
        $this->owner = joinAs(User::factory()->create(['name' => 'Ada Owner', 'email' => 'ada.owner@example.com']), $this->acme);
        $this->admin = joinAs(User::factory()->create(['name' => 'Seyi Admin', 'email' => 'seyi.admin@example.com']), $this->acme, OrganizationRole::Admin);
        $this->agent = joinAs(User::factory()->create(['name' => 'Bola Agent', 'email' => 'bola.agent@example.com']), $this->acme, OrganizationRole::Agent);
        $this->freeAgent = joinAs(User::factory()->create(['name' => 'Chika Agent', 'email' => 'chika.agent@example.com']), $this->acme, OrganizationRole::Agent);
        $this->otherOwner = joinAs(User::factory()->create(['name' => 'Chidi Owner', 'email' => 'chidi.owner@example.com']), $this->other);

        $this->palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court']);
        $this->oak = buildingIn($this->acme, ['name' => 'Oak Lodge', 'slug' => 'oak-lodge']);
        assignIn($this->acme, $this->agent, $this->palm);

        $this->flat3b = unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 3B']);
        $this->flat4c = unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 4C']);
        $this->gatehouse = unitIn($this->acme, $this->palm, ['unit_label' => 'Gatehouse']);
        $this->oakUnit = unitIn($this->acme, $this->oak, ['unit_label' => 'Unit 1']);

        $this->kemi = User::factory()->create(['name' => 'Kemi Adebayo', 'email' => 'kemi.adebayo@example.com', 'phone' => '+2348031112222']);
        $this->kemiAtAcme = tenantIn($this->acme, ['first_name' => 'Kemi', 'last_name' => 'Adebayo', 'phone' => '+2348031112222', 'email' => 'kemi.adebayo@example.com', 'user_id' => $this->kemi->id]);
        $this->lease = activeLease($this->acme, $this->owner, $this->flat3b, $this->kemiAtAcme);

        $this->tunde = User::factory()->create(['name' => 'Tunde Bakare', 'email' => 'tunde.bakare@example.com', 'phone' => '+2348034445555']);
        $this->tundeAtAcme = tenantIn($this->acme, ['first_name' => 'Tunde', 'last_name' => 'Bakare', 'phone' => '+2348034445555', 'email' => 'tunde.bakare@example.com', 'user_id' => $this->tunde->id]);
        $this->tundeLease = activeLease($this->acme, $this->owner, $this->flat4c, $this->tundeAtAcme, ['rent' => '1,800,000']);

        $this->femi = tenantIn($this->acme, ['first_name' => 'Femi', 'last_name' => 'Okon', 'phone' => '+2348036660000', 'email' => 'femi.okon@example.com']);
        $this->femiLease = activeLease($this->acme, $this->owner, $this->oakUnit, $this->femi, ['rent' => '900,000']);

        $foreignBuilding = buildingIn($this->other, ['name' => 'Foreign Court', 'slug' => 'foreign-court']);
        $this->foreignUnit = unitIn($this->other, $foreignBuilding, ['unit_label' => 'Suite 9']);
        $this->foreignTenant = tenantIn($this->other, ['first_name' => 'Foreign', 'last_name' => 'Tenant', 'phone' => '+2348035550000', 'email' => 'foreign.tenant@example.com']);
        $this->foreignLease = activeLease($this->other, $this->otherOwner, $this->foreignUnit, $this->foreignTenant, ['rent' => '2,400,000']);
    })->call($test);
}
