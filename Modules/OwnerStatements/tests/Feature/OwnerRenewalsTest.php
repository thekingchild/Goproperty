<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;
use Modules\Leasing\Leases\LeaseService;
use Modules\OwnerStatements\Statements\OwnerStatements;

require_once __DIR__.'/statement-world.php';

/*
 * P08-05 ACs: upcoming renewals list exactly the leases of the owner's
 * buildings ending within 90 days (from today, Lagos), with their renewal
 * state; owners see tenant names only, and the tenant's phone and email
 * only when the agency switches on "share tenant contact details" for that
 * owner. Today is 1 February 2027: 90 days on is 2 May 2027.
 */

beforeEach(function () {
    Notification::fake();
    statementBaseWorld();

    $unit = fn (string $label) => unitIn($this->acme, $this->marina, ['unit_label' => $label]);
    $tenant = fn (string $first, string $last, string $phone) => tenantIn($this->acme, ['first_name' => $first, 'last_name' => $last, 'phone' => $phone, 'email' => strtolower($first).'.'.strtolower($last).'@example.com']);

    // Unit 3: ends 31 Mar, its renewal drafted. Unit 4: ends 2 May (day 90), renews by itself.
    // Unit 5: ends 3 May (day 91). Unit 6: ended yesterday (31 Jan, not expired yet). Unit 7: ends 30 Apr, not renewed.
    $this->dayo = $tenant('Dayo', 'Ade', '+2348031230003');
    $this->dayoLease = monthlyLease($this->acme, $this->owner, $unit('Unit 3'), $this->dayo, ['endDate' => '2027-03-31']);
    $this->efeLease = monthlyLease($this->acme, $this->owner, $unit('Unit 4'), $tenant('Efe', 'Okon', '+2348031230004'), ['endDate' => '2027-05-02', 'autoRenew' => true]);
    $this->funkeLease = monthlyLease($this->acme, $this->owner, $unit('Unit 5'), $tenant('Funke', 'Ola', '+2348031230005'), ['endDate' => '2027-05-03']);
    $this->gbengaLease = monthlyLease($this->acme, $this->owner, $unit('Unit 6'), $tenant('Gbenga', 'Ilo', '+2348031230006'), ['endDate' => '2027-01-31']);
    $this->halimaLease = monthlyLease($this->acme, $this->owner, $unit('Unit 7'), $tenant('Halima', 'Sule', '+2348031230007'), ['endDate' => '2027-04-30']);
    // Femi's Cedar House: a lease ending in the window, never Ngozi's.
    $this->femiLease = monthlyLease($this->acme, $this->owner, unitIn($this->acme, $this->cedar, ['unit_label' => 'Flat 2']), $tenant('Ibrahim', 'Musa', '+2348031230008'), ['endDate' => '2027-03-15']);

    $this->travelTo('2027-02-01 10:00:00');
    $this->renewal = withoutContractPdfs(fn () => inOrganization($this->acme, fn () => app(LeaseService::class)->renew($this->owner, storedLease($this->acme, $this->dayoLease), leaseInput([
        'billingCycle' => 'monthly', 'startDate' => '2027-04-01', 'endDate' => '2028-03-31', 'rent' => '160,000', 'deposit' => '0',
    ]))));
});

afterEach(fn () => billsFreshRequest());

it('acceptance: lists exactly the leases ending within the next 90 days, with their renewal state', function () {
    $renewals = inOrganization($this->acme, fn () => app(OwnerStatements::class)->renewals($this->ngoziAtAcme->id));

    expect(array_map(fn ($line) => [$line->leaseId, $line->unit, $line->endDate->toDateString(), $line->state], $renewals))->toBe([
        [$this->dayoLease->id, 'Unit 3', '2027-03-31', 'renewal_drafted'],
        [$this->halimaLease->id, 'Unit 7', '2027-04-30', 'not_renewed'],
        [$this->efeLease->id, 'Unit 4', '2027-05-02', 'auto_renews'],
    ])
        ->and($renewals[0]->renewalStart?->toDateString())->toBe('2027-04-01')
        ->and($renewals[0]->tenantName)->toBe('Dayo Ade');

    // The pages: the owner portal (with a link to each lease) and the agency's.
    $page = ownerPortalPage($this->ngozi, '/statements')->assertOk()
        ->assertSee('Renewal drafted')->assertSee('Rolls on (auto-renew)')->assertSee('Not renewed yet')
        ->assertDontSee('Funke Ola')->assertDontSee('Gbenga Ilo')->assertDontSee('Ibrahim Musa');
    preg_match_all('/data-test="renewal" data-lease="(\d+)"/', (string) $page->getContent(), $listed);
    expect(array_map('intval', $listed[1]))->toBe([$this->dayoLease->id, $this->halimaLease->id, $this->efeLease->id]);

    $agency = agencyStatementPage($this->owner, $this->ngoziAtAcme)->assertOk();
    preg_match_all('/data-test="renewal" data-lease="(\d+)"/', (string) $agency->getContent(), $listed);
    expect(array_map('intval', $listed[1]))->toBe([$this->dayoLease->id, $this->halimaLease->id, $this->efeLease->id]);

    // Femi sees his own.
    $femi = ownerPortalPage($this->femi, '/statements')->assertOk()->assertSee('Ibrahim Musa')->assertDontSee('Dayo Ade');
    preg_match_all('/data-test="renewal" data-lease="(\d+)"/', (string) $femi->getContent(), $listed);
    expect(array_map('intval', $listed[1]))->toBe([$this->femiLease->id]);
});

it('acceptance: hides tenants\' phone and email from the owner unless the agency shares them with that owner', function () {
    $contacts = ['+2348031230003', 'dayo.ade@example.com', '+2348031112222', 'kemi.adebayo@example.com'];

    $statements = ownerPortalPage($this->ngozi, '/statements')->assertOk()->assertSee('Dayo Ade');
    $lease = ownerPortalPage($this->ngozi, "/leases/{$this->lease->id}")->assertOk()->assertSee('Kemi Adebayo')->assertDontSee('data-test="owner-lease-tenant-contacts"', false);
    foreach ($contacts as $detail) {
        $statements->assertDontSee($detail);
        $lease->assertDontSee($detail);
    }

    // Switched on for Ngozi at Adeyemi: her renewals and lease pages show them.
    setStatementTerms($this->acme, $this->owner, $this->ngoziAtAcme, ['shareTenantContacts' => true]);
    ownerPortalPage($this->ngozi, '/statements')->assertOk()->assertSee('+2348031230003')->assertSee('dayo.ade@example.com');
    ownerPortalPage($this->ngozi, "/leases/{$this->lease->id}")->assertOk()->assertSee('data-test="owner-lease-tenant-contacts"', false)->assertSee('+2348031112222')->assertSee('kemi.adebayo@example.com');

    // Never on the monthly statement (names only), never to another owner, never at another agency.
    ownerPortalPage($this->ngozi, "/statements/{$this->ngoziAtAcme->id}/2027-01")->assertOk()->assertDontSee('+2348031112222')->assertDontSee('kemi.adebayo@example.com');
    ownerPortalPage($this->femi, '/statements')->assertOk()->assertDontSee('+2348031230008')->assertDontSee('ibrahim.musa@example.com');
    ownerPortalPage($this->ngozi, "/leases/{$this->belloLease->id}")->assertOk()->assertDontSee('+2348036667777')->assertDontSee('musa.danjuma@example.com');

    // Switched off again.
    setStatementTerms($this->acme, $this->owner, $this->ngoziAtAcme, ['shareTenantContacts' => false]);
    ownerPortalPage($this->ngozi, "/leases/{$this->lease->id}")->assertOk()->assertDontSee('+2348031112222');
});
