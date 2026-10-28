<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Documents\DocumentCounter;
use App\Enums\InvitationChannel;
use App\Enums\InvitationKind;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\Platform\Impersonation\ImpersonationMode;
use App\Tenancy\OrganizationContext;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Modules\Import\Batches\ImportRefused;
use Modules\Import\Enums\ImportStatus;
use Modules\Leasing\Enums\LeaseStatus;
use Modules\Leasing\Leases\LeaseService;
use Modules\Leasing\Models\Lease;
use Modules\Properties\Enums\UnitStatus;
use Modules\Tenants\Notifications\TenantPortalInvitation;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/helpers.php';

/*
 * P03-13a: re-importing the Leases sheet updates instead of duplicating;
 * rolling an import back archives its leases and frees their units, and is
 * refused once a bill or a payment exists; nobody is contacted until the
 * agency chooses "Invite tenants now"; another organization's records are
 * never matched or touched.
 */

beforeEach(function () {
    Storage::fake('media');
    holdInvoicePdfs();
    holdReceiptPdfs();
    seedOrganizationRoles();
    $this->travelTo('2026-10-28 10:00:00');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $this->owner = joinAs(User::factory()->create(), $this->acme);
});

afterEach(function () {
    app(OrganizationContext::class)->clear();
});

it('acceptance: updates the leases when the same file is imported again, instead of adding them twice', function () {
    $first = portfolioImport($this->acme, $this->owner, ongoingRentsWorkbook());
    $before = leasesOf($this->acme)->keyBy('id');
    $counter = fn () => inOrganization($this->acme, fn () => DocumentCounter::query()->sum('last_seq'));
    $numbers = $counter();

    // The same file: matched by Your ID.
    $again = portfolioDryRun($this->acme, $this->owner, ongoingRentsWorkbook());
    expect(portfolioCounts($again, 'leases'))->toMatchArray(['create' => 0, 'update' => 3, 'restore' => 0, 'skip' => 0])
        ->and(leaseIssues($again))->toBe([[4, 'End date', 'F', 'warning', 'This lease ended on 31/01/2023, so it is imported as expired (history): it is not billed and does not let the unit.']]);
    portfolioCommit($this->acme, $this->owner, $again);

    // The same file without Your ID: matched by tenant, unit and start date.
    $rows = array_map(fn (array $row): array => ['', ...array_slice($row, 1)], standardLeaseRows());
    $natural = portfolioImport($this->acme, $this->owner, ongoingRentsWorkbook($rows));

    $after = leasesOf($this->acme);
    expect($natural->result_counts['targets']['leases'])->toMatchArray(['create' => 0, 'update' => 3])
        ->and($after)->toHaveCount(3)
        ->and($after->map(fn (Lease $lease) => [$lease->id, $lease->contract_number, $lease->next_billing_date?->toDateString(), $lease->import_batch_id])->all())
        ->toBe($before->values()->map(fn (Lease $lease) => [$lease->id, $lease->contract_number, $lease->next_billing_date?->toDateString(), $first->id])->all())
        ->and($counter())->toBe($numbers)
        ->and(depositEntriesOf($this->acme, $after[0]))->toBe([['held', 15_000_000]])
        ->and(AuditEntry::query()->where('event', 'lease.imported')->count())->toBe(3);

    // New terms update the lease while it has no bills, audited.
    $rows = standardLeaseRows();
    $rows[1][7] = '130,000';
    portfolioImport($this->acme, $this->owner, ongoingRentsWorkbook($rows));
    $monthly = storedLease($this->acme, $after[1]);
    $updated = AuditEntry::query()->where('event', 'lease.imported')->latest('id')->first();

    expect($monthly->rent_amount_kobo->kobo)->toBe(13_000_000)
        ->and($updated->subject_id)->toBe($monthly->id)
        ->and($updated->properties['changed'])->toBe(['rent_amount_kobo'])
        ->and($updated->properties['action'])->toBe('update');

    // Once billed, an import can no longer change its terms.
    $this->travelTo('2026-11-08 00:10:00');
    runBillGeneration();
    $rows[1][7] = '140,000';
    $refused = portfolioDryRun($this->acme, $this->owner, ongoingRentsWorkbook($rows));

    expect(array_values(array_filter(leaseIssues($refused), fn (array $issue): bool => $issue[3] === 'error')))->toBe([
        [3, null, null, 'error', 'Bills have been issued for this lease (LS-ADR-2026-000002), so an import cannot change its terms. Change it on its page.'],
    ])->and(storedLease($this->acme, $monthly)->rent_amount_kobo->kobo)->toBe(13_000_000);
});

it('rolls an import back: its leases are archived and their units freed, audited; importing again brings them back', function () {
    $batch = portfolioImport($this->acme, $this->owner, ongoingRentsWorkbook());
    $leases = leasesOf($this->acme);

    $rolledBack = portfolioRollback($this->acme, $this->owner, $batch);

    expect($rolledBack->status)->toBe(ImportStatus::RolledBack)
        ->and(leasesOf($this->acme)->every->trashed())->toBeTrue()
        ->and(unitsOf($this->acme)->every->trashed())->toBeTrue()
        ->and(unitsOf($this->acme)->pluck('status')->unique()->values()->all())->toBe([UnitStatus::Vacant])
        ->and(tenantsOf($this->acme)->every->trashed())->toBeTrue()
        ->and(AuditEntry::query()->where('event', 'lease.import_rolled_back')->count())->toBe(3)
        ->and(depositEntriesOf($this->acme, $leases[0]))->toBe([['held', 15_000_000]]);

    // Importing the file again brings back the same leases, with their numbers and deposits.
    $again = portfolioDryRun($this->acme, $this->owner, ongoingRentsWorkbook());
    expect(portfolioCounts($again, 'leases'))->toMatchArray(['create' => 0, 'restore' => 3]);
    portfolioCommit($this->acme, $this->owner, $again);

    $restored = leasesOf($this->acme);
    expect($restored->pluck('id')->all())->toBe($leases->pluck('id')->all())
        ->and($restored->every->trashed())->toBeFalse()
        ->and($restored->pluck('status')->all())->toBe([LeaseStatus::Active, LeaseStatus::Active, LeaseStatus::Expired])
        ->and($restored->pluck('contract_number')->all())->toBe($leases->pluck('contract_number')->all())
        ->and($restored->pluck('import_batch_id')->unique()->all())->toBe([$again->id])
        ->and(depositEntriesOf($this->acme, $restored[0]))->toBe([['held', 15_000_000]])
        ->and(unitsOf($this->acme)->pluck('status', 'unit_label')->all())->toBe(['Flat 1' => UnitStatus::Occupied, 'Flat 2' => UnitStatus::Occupied, 'Flat 3' => UnitStatus::Vacant]);
});

it('acceptance: refuses a rollback once a bill has been issued for an imported lease', function () {
    $this->travelTo('2026-12-20 10:00:00');
    $batch = portfolioImport($this->acme, $this->owner, ongoingRentsWorkbook());

    $this->travelTo('2026-12-25 00:10:00');
    runBillGeneration();
    expect(billsOf($this->acme))->not->toHaveCount(0);

    expect(fn () => portfolioRollback($this->acme, $this->owner, $batch))
        ->toThrow(ImportRefused::class, 'Some leases from this import have been used since');
    expect(leasesOf($this->acme)->filter->trashed())->toHaveCount(0)
        ->and(unitsOf($this->acme)->filter->trashed())->toHaveCount(0);
});

it('acceptance: refuses a rollback once a payment has been recorded for an imported tenant', function () {
    $batch = portfolioImport($this->acme, $this->owner, ongoingRentsWorkbook());
    $chika = tenantsOf($this->acme)->firstWhere('first_name', 'Chika');

    recordPayment($this->acme, $this->owner, $chika, '50,000');

    expect(fn () => portfolioRollback($this->acme, $this->owner, $batch))
        ->toThrow(ImportRefused::class, 'Some tenants from this import have been used since');
    expect(tenantsOf($this->acme)->filter->trashed())->toHaveCount(0)
        ->and(leasesOf($this->acme)->filter->trashed())->toHaveCount(0);
});

it('refuses a rollback once an imported lease is renewed, ended on its page, or its contract issued', function () {
    $batch = portfolioImport($this->acme, $this->owner, ongoingRentsWorkbook());
    $yearly = leasesOf($this->acme)->first();

    // Its contract printed from the lease page (issued on first download).
    leasesFreshRequest();
    $this->actingAs($this->owner)->get("/o/acme-homes/leases/{$yearly->id}/contract")->assertRedirect();
    expect(fn () => portfolioRollback($this->acme, $this->owner, $batch))->toThrow(ImportRefused::class, 'Some leases from this import have been used since');

    // Ended on its page (another file, another batch).
    $other = portfolioImport($this->acme, $this->owner, ongoingRentsWorkbook(sheets: ['Leases' => [LEASES_HEADER, leaseRow(['Your ID' => 'L-9', 'Tenant' => 'T-3', 'Building' => 'Palm Court', 'Unit' => 'Flat 3', 'Start date' => '01/06/2026', 'End date' => '31/05/2027', 'Billing cycle' => 'Yearly', 'Rent per period' => '1,800,000', 'Paid until' => '31/05/2027'])]]));
    $ended = leasesOf($this->acme)->firstWhere('external_ref', 'L-9');
    inOrganization($this->acme, fn () => app(LeaseService::class)->terminate($this->owner, $ended, 'Moved out early'));
    expect(fn () => portfolioRollback($this->acme, $this->owner, $other))->toThrow(ImportRefused::class, 'Some leases from this import have been used since');
});

it('acceptance: contacts no tenant until the agency chooses "Invite tenants now", then invites by email and prints a QR sheet for the rest', function () {
    Notification::fake();
    Mail::fake();
    $batch = portfolioImport($this->acme, $this->owner, ongoingRentsWorkbook());

    Notification::assertNothingSent();
    Mail::assertNothingOutgoing();
    expect(leaseImportWhatsApp())->toHaveCount(0)
        ->and(leaseImportInvitations($this->acme)->count())->toBe(0);

    // The report offers it; nothing happens until a button is pressed.
    leasesFreshRequest();
    $this->actingAs($this->owner)->get("/o/acme-homes/imports/{$batch->id}")->assertOk()
        ->assertSee('Invite tenants now')
        ->assertSee('data-test="invite-tenants-email"', false)
        ->assertSee('Invite by email (2)')
        ->assertSee('Printable QR sheet (3)');
    Notification::assertNothingSent();

    invitationsCall($this->owner, $batch, 'inviteByEmail')->assertOk();

    Notification::assertSentOnDemandTimes(TenantPortalInvitation::class, 2);
    $emailed = collect(Notification::sentNotifications()[AnonymousNotifiable::class] ?? [])->flatten(2)
        ->map(fn (array $sent): string => $sent['notifiable']->routes['mail'])->sort()->values()->all();
    expect($emailed)->toBe(['adaeze.okafor@example.com', 'bayo.adeleke@example.com']);

    // The tenant without an email: a printable sheet of QR invitation links, shown once.
    $response = invitationsCall($this->owner, $batch, 'printSheet')->assertOk();
    $html = LivewireHttp::html($response);

    expect(substr_count($html, 'data-test="invitation-sheet-entry"'))->toBe(1)
        ->and($html)->toContain('Chika Nwosu')->toContain('data-test="invitation-sheet"')->toContain('src="data:image/')
        ->and(leaseImportWhatsApp())->toHaveCount(0)
        ->and(leaseImportInvitations($this->acme)->pluck('channel')->all())
        ->toBe([InvitationChannel::Email, InvitationChannel::Email, InvitationChannel::Link]);
    Notification::assertSentOnDemandTimes(TenantPortalInvitation::class, 2);

    // Everyone has an invitation now: pressing again sends nothing more.
    invitationsCall($this->owner, $batch, 'inviteByEmail')->assertOk();
    Notification::assertSentOnDemandTimes(TenantPortalInvitation::class, 2);
});

it('invites the import\'s tenants by the agency\'s WhatsApp when the agency chooses it, never by SMS', function () {
    Notification::fake();
    $batch = portfolioImport($this->acme, $this->owner, ongoingRentsWorkbook());

    invitationsCall($this->owner, $batch, 'inviteByWhatsApp')->assertOk();

    expect(leaseImportWhatsApp()->sortBy(1)->values()->all())->toBe([
        ['tenant_invitation', '+2348035550101'],
        ['tenant_invitation', '+2348035550102'],
        ['tenant_invitation', '+2348035550103'],
    ]);
    Notification::assertNothingSent();
});

it('refuses "Invite tenants now" to impersonating GoProperty staff', function () {
    seedStaffRoles();
    Notification::fake();
    $batch = portfolioImport($this->acme, $this->owner, ongoingRentsWorkbook());
    $staff = staffMember(['name' => 'Ada Okafor', 'email' => 'ada@goproperty.ng']);
    $handOff = startSupport($staff, $this->owner, $this->acme, ImpersonationMode::Write);
    enterSupport($handOff)->assertRedirect('/o/acme-homes');

    $page = (string) asSupport()->get("/o/acme-homes/imports/{$batch->id}")->assertOk()->getContent();
    preg_match_all('/wire:snapshot="([^"]+)"/', $page, $matches);
    $snapshot = collect($matches[1])->map(fn (string $raw): string => html_entity_decode($raw, ENT_QUOTES | ENT_HTML5))
        ->first(fn (string $snapshot): bool => (json_decode($snapshot, true)['memo']['name'] ?? null) === 'tenants::import-invitations');
    freshRequest();

    foreach (['inviteByEmail', 'inviteByWhatsApp', 'printSheet'] as $method) {
        LivewireHttp::update(test(), $snapshot, [], [[$method]])->assertForbidden();
        freshRequest();
    }

    Notification::assertNothingSent();
    expect(leaseImportWhatsApp())->toHaveCount(0)
        ->and(leaseImportInvitations($this->acme)->count())->toBe(0);
});

it('never matches, bills, invites or rolls back another organization\'s tenants, units or leases', function () {
    $other = Organization::factory()->create(['slug' => 'other-realty', 'name' => 'Other Realty']);
    $theirOwner = joinAs(User::factory()->create(), $other);
    // Their portfolio has the same building, flats, phones and lease IDs.
    $theirs = portfolioImport($other, $theirOwner, ongoingRentsWorkbook());
    $theirLeases = leasesOf($other);

    // Our file naming only leases knows none of their tenants, buildings or units.
    $alone = portfolioDryRun($this->acme, $this->owner, ongoingRentsWorkbook(sheets: ['Buildings' => null, 'Units' => null, 'Tenants' => null]));
    expect(array_column(leaseIssues($alone), 4))->toBe([
        'No tenant has the phone number or ID "T-1", here or in the Tenants sheet. Add them to the Tenants sheet first.',
        'No building is called "Palm Court", here or in the Buildings sheet. Add it to the Buildings sheet first.',
        'No tenant has the phone number or ID "0803 555 0102", here or in the Tenants sheet. Add them to the Tenants sheet first.',
        'No building is called "Palm Court", here or in the Buildings sheet. Add it to the Buildings sheet first.',
        'No tenant has the phone number or ID "T-3", here or in the Tenants sheet. Add them to the Tenants sheet first.',
        'No building is called "Palm Court", here or in the Buildings sheet. Add it to the Buildings sheet first.',
    ]);

    // The full file creates our own records: nothing of theirs is matched (no updates), changed or numbered.
    $ours = portfolioImport($this->acme, $this->owner, ongoingRentsWorkbook());
    expect($ours->result_counts['targets']['leases'])->toMatchArray(['create' => 3, 'update' => 0, 'restore' => 0])
        ->and(leasesOf($this->acme)->pluck('contract_number')->all())->toBe(['LS-ADR-2026-000001', 'LS-ADR-2026-000002', 'PC/2021/007']);

    // Their invitations panel shows none of ours, and ours none of theirs (an import of theirs by id is empty here).
    Notification::fake();
    invitationsCall($this->owner, $ours, 'inviteByEmail')->assertOk();
    $emailedTenants = Invitation::query()->withoutGlobalScopes()->where('kind', InvitationKind::Tenant)->pluck('organization_id')->unique()->values()->all();
    expect($emailedTenants)->toBe([$this->acme->id]);
    leasesFreshRequest();
    $this->actingAs($this->owner)->get("/o/acme-homes/imports/{$theirs->id}")->assertNotFound();

    // Rollback and billing stay in each organization.
    portfolioRollback($this->acme, $this->owner, $ours);
    $this->travelTo('2026-11-08 00:10:00');
    runBillGeneration();

    expect(billsOf($this->acme))->toHaveCount(0)
        ->and(billsOf($other)->pluck('lease_id')->all())->toBe([$theirLeases[1]->id]);

    expect(leasesOf($other)->map(fn (Lease $lease) => [$lease->id, $lease->status, $lease->trashed(), $lease->rent_amount_kobo->kobo])->all())
        ->toBe($theirLeases->map(fn (Lease $lease) => [$lease->id, $lease->status, false, $lease->rent_amount_kobo->kobo])->all())
        ->and(unitsOf($other)->filter->trashed())->toHaveCount(0)
        ->and(unitsOf($other)->pluck('status', 'unit_label')->all())->toBe(['Flat 1' => UnitStatus::Occupied, 'Flat 2' => UnitStatus::Occupied, 'Flat 3' => UnitStatus::Vacant]);
});
