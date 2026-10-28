<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\InvitationChannel;
use App\Enums\InvitationKind;
use App\Enums\InvitationStatus;
use App\Models\Invitation;
use App\Support\Money;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Contracts\BillLedger;
use Modules\Handovers\Enums\CreditSettlement;
use Modules\Handovers\Enums\HandoverStatus;
use Modules\Handovers\Handovers\HandoverCredits;
use Modules\Leasing\Models\Lease;
use Modules\Payments\Events\PaymentConfirmed;
use Modules\Tenants\Models\TenantProfile;
use Modules\Tenants\Portal\SignInLinks;
use Modules\Tenants\Portal\TenantInvitations;
use Modules\Tenants\Profiles\TenantProfiles;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/return-world.php';
require_once __DIR__.'/../../../Tenants/tests/Feature/Portal/helpers.php';

/*
 * P04-04 (BILLING_PAYMENTS.md § 7.4; product owner 2026-10-28): tenants
 * are copied (or matched), never moved, so the record they leave behind
 * keeps its history and shows "Moved to {organization} on {date}"; it
 * cannot be archived while it has unpaid bills or unsettled credit, and
 * archives itself once both are clear (at once on the events, or by the
 * daily sweep). When tenants come back (a management ends, or a one-way
 * return), their original record recorded on the handover is used first,
 * then the phone: a tenant whose phone changed meanwhile gets no third
 * record. Their pending portal invitations and sign-in links on the side
 * they left are revoked.
 *
 * The world is handover-world.php: Tunde has ₦100,000 of credit at Eze
 * Properties; Kemi owes her first rent bill and the security levy; Musa
 * owes his rent; Bisi's lease is a draft (nothing owed either way); Femi
 * stays (Cedar House).
 */

beforeEach(fn () => handoverWorld());

afterEach(fn () => handoversFreshRequest());

/** The landlord's tenants, archived ones too, by phone. */
function landlordTenantPhones(): array
{
    return inOrganization(test()->landlord, fn (): array => TenantProfile::query()->withTrashed()->orderBy('phone')->pluck('phone')->all());
}

/** Whether the landlord's record of the tenant is archived now. */
function landlordArchived(TenantProfile $tenant): bool
{
    return inOrganization(test()->landlord, fn (): bool => TenantProfile::query()->withTrashed()->findOrFail($tenant->id)->trashed());
}

/** What the tenant still owes the landlord, as the payment form takes it ("1,350,000"). */
function owedToLandlord(TenantProfile $tenant): string
{
    $owed = inOrganization(test()->landlord, fn (): Money => Money::sum(array_map(fn ($bill): Money => $bill->outstanding, app(BillLedger::class)->openBills($tenant->id))));

    return number_format(intdiv($owed->kobo, 100));
}

it('lands a returning tenant whose phone changed on their original record when a management ends (no third record)', function () {
    $handover = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm, $this->lagoon]);
    acceptHandover($this->ada, $this->agency, $handover);
    $phonesBefore = landlordTenantPhones();

    // At the agency, Tunde's number changes.
    $agencyTunde = inOrganization($this->agency, fn () => TenantProfile::query()->where('phone', '+2348034445555')->sole());
    inOrganization($this->agency, fn () => $agencyTunde->forceFill(['phone' => '+2348039991111'])->save());

    giveHandoverNotice($this->ada, $this->agency, $handover);
    $this->travelTo(now()->addDays(31));
    runHandoverJob('handovers-end');
    expect(storedHandover($handover)->status)->toBe(HandoverStatus::Ended);

    // Tunde's lease is back on his original record; no record with the new number was made; Bisi's (archived by itself) is back.
    expect(inOrganization($this->landlord, fn () => Lease::query()->whereKey([$this->tundeLease->id, $this->kemiLease->id, $this->bisiDraft->id])->orderBy('id')->pluck('tenant_profile_id')->all()))
        ->toBe([$this->tunde->id, $this->kemi->id, $this->bisi->id])
        ->and(landlordTenantPhones())->toBe($phonesBefore)
        ->and(inOrganization($this->landlord, fn () => TenantProfile::query()->withTrashed()->count()))->toBe(5)
        ->and(landlordArchived($this->bisi))->toBeFalse()
        ->and(AuditEntry::query()->where('event', 'handover.records_moved_in')->orderByDesc('id')->first()->properties['counts']['tenants_back_on_their_record'] ?? null)->toBe(4);
});

it('lands a returning tenant whose phone changed on their original record on a one-way return (no third record)', function () {
    // Eze hands Palm Court to the agency: Tunde is copied there.
    $management = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm]);
    acceptHandover($this->ada, $this->agency, $management);
    $agencyTunde = inOrganization($this->agency, fn () => TenantProfile::query()->where('phone', '+2348034445555')->sole());
    inOrganization($this->agency, fn () => $agencyTunde->forceFill(['phone' => '+2348039991111'])->save());

    // The agency adds Harbour Point for Ngozi (her owner record there, linked by the handover) and lets a flat to Tunde.
    $ngoziAtAgency = storedHandover($management)->owner_profile_id;
    $harbour = buildingIn($this->agency, ['name' => 'Harbour Point', 'slug' => 'harbour-point', 'address' => '5 Marina Road, Lagos Island', 'owner_profile_id' => $ngoziAtAgency]);
    $flat = unitIn($this->agency, $harbour, ['unit_label' => 'Flat A']);
    $lease = activeLease($this->agency, $this->ada, $flat, $agencyTunde, ['startDate' => '2027-02-01', 'endDate' => '2028-01-31', 'deposit' => '0']);
    $phonesBefore = landlordTenantPhones();

    // It returns Harbour Point to Eze, for good.
    $return = offerReturn($this->ada, $this->agency, $this->landlord, [$harbour]);
    acceptHandover($this->ngozi, $this->landlord, incomingHandover($this->landlord, $return));

    expect(storedHandover($return)->status)->toBe(HandoverStatus::Returned)
        ->and(inOrganization($this->landlord, fn () => Lease::query()->findOrFail($lease->id)->tenant_profile_id))->toBe($this->tunde->id)
        ->and(landlordTenantPhones())->toBe($phonesBefore)
        ->and(storedHandover($return)->tenants->sole()->receiving_tenant_profile_id)->toBe($this->tunde->id)
        // Palm Court is still managed by the agency, under its own handover.
        ->and(storedHandover($management)->status)->toBe(HandoverStatus::Active)
        ->and(organizationsOf('properties', [$this->palm->id, $harbour->id]))->toBe([$this->palm->id => $this->agency->id, $harbour->id => $this->landlord->id]);
});

it('refuses to archive a moved tenant with an unpaid bill or unsettled credit, shows where they moved, and archives the record once both are clear', function () {
    $handover = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm, $this->lagoon]);
    acceptHandover($this->ada, $this->agency, $handover);
    $archive = fn (TenantProfile $tenant) => inOrganization($this->landlord, fn () => app(TenantProfiles::class)->archive($this->ngozi, TenantProfile::query()->findOrFail($tenant->id)));

    // Bisi (a draft lease, nothing owed either way) archived herself at once.
    expect(landlordArchived($this->bisi))->toBeTrue()
        ->and(AuditEntry::query()->where('event', 'tenant.archived')->where('subject_id', $this->bisi->id)->sole()->properties['reason'])->toBe('moved_and_settled');

    // Kemi owes her bills; Tunde has credit; Musa owes his rent: none can be archived.
    expect(fn () => $archive($this->kemi))->toThrow(ValidationException::class, 'Kemi Adebayo moved to Adeyemi Realty Ltd on 3 January 2027 but still owes')
        ->and(fn () => $archive($this->tunde))->toThrow(ValidationException::class, 'of credit here that is not settled yet')
        ->and(fn () => $archive($this->musa))->toThrow(ValidationException::class, 'still owes')
        ->and(landlordArchived($this->kemi))->toBeFalse()
        ->and(landlordArchived($this->tunde))->toBeFalse();

    // The record shows where the tenant went, and the Archive button explains the refusal.
    tenantsPage($this->ngozi, "/{$this->kemi->id}", 'eze-properties')->assertOk()
        ->assertSee('data-test="moved-to"', false)
        ->assertSee('Moved to Adeyemi Realty Ltd on 3 January 2027');
    $refused = LivewireHttp::update($this->actingAs($this->ngozi), tenantsSnapshot($this->ngozi, "/{$this->kemi->id}", 'eze-properties'), [], [['archive']])->assertOk();
    expect(LivewireHttp::html($refused))->toContain('still owes');

    // Femi did not move: no marker.
    tenantsPage($this->ngozi, "/{$this->femi->id}", 'eze-properties')->assertOk()->assertDontSee('data-test="moved-to"', false);

    // Kemi pays what she owes the landlord: her record archives itself at once.
    confirmedPayment($this->landlord, $this->ngozi, $this->kemi, owedToLandlord($this->kemi));
    expect(landlordArchived($this->kemi))->toBeTrue();
    tenantsPage($this->ngozi, "/{$this->kemi->id}", 'eze-properties')->assertOk()
        ->assertSee('Moved to Adeyemi Realty Ltd on 3 January 2027')
        ->assertSee('this record archived itself');

    // Tunde's credit is refunded on the handover's page: his record archives itself too.
    $line = creditLine($handover, $this->tunde->id);
    inOrganization($this->landlord, fn () => app(HandoverCredits::class)->choose($this->ngozi, storedHandover($handover), $line->id, CreditSettlement::Refund));
    expect(landlordArchived($this->tunde))->toBeFalse();
    inOrganization($this->landlord, fn () => app(HandoverCredits::class)->refund($this->ngozi, storedHandover($handover), $line->id, liveCredits($handover)[$line->id]));
    expect(landlordArchived($this->tunde))->toBeTrue();

    // Musa's payment is confirmed without the event reaching the Tenants module: the daily sweep archives his record.
    Event::fakeFor(fn () => confirmedPayment($this->landlord, $this->ngozi, $this->musa, owedToLandlord($this->musa)), [PaymentConfirmed::class]);
    expect(landlordArchived($this->musa))->toBeFalse();
    billingScheduledEvent('tenants-archive-moved', '50 3 * * *')->run(app());
    expect(landlordArchived($this->musa))->toBeTrue();

    // Femi still holds his lease: nothing touched him; running the sweep again changes nothing.
    $audits = AuditEntry::query()->count();
    billingScheduledEvent('tenants-archive-moved', '50 3 * * *')->run(app());
    expect(landlordArchived($this->femi))->toBeFalse()
        ->and(AuditEntry::query()->count())->toBe($audits)
        ->and(AuditEntry::query()->where('event', 'tenant.archived')->whereNull('causer_id')->count())->toBe(4);
});

it('restores a moved tenant\'s archived record when the management ends', function () {
    $handover = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->lagoon]);
    acceptHandover($this->ada, $this->agency, $handover);
    expect(landlordArchived($this->bisi))->toBeTrue();

    giveHandoverNotice($this->ngozi, $this->landlord, $handover);
    $this->travelTo(now()->addDays(31));
    runHandoverJob('handovers-end');

    expect(landlordArchived($this->bisi))->toBeFalse()
        ->and(inOrganization($this->landlord, fn () => Lease::query()->findOrFail($this->bisiDraft->id)->tenant_profile_id))->toBe($this->bisi->id);
    tenantsPage($this->ngozi, "/{$this->bisi->id}", 'eze-properties')->assertOk()->assertDontSee('data-test="moved-to"', false);
});

it('revokes moved tenants\' pending portal invitations and sign-in links on the side they left, audited', function () {
    // Before the handover: Kemi and Femi are invited by link; Tunde has a phone-only account and a sign-in link.
    foreach ([$this->kemi, $this->femi] as $tenant) {
        inOrganization($this->landlord, fn () => app(TenantInvitations::class)->invite($this->ngozi, TenantProfile::query()->findOrFail($tenant->id), InvitationChannel::Link));
    }

    $account = portalAccountFor($this->landlord, $this->tunde);
    inOrganization($this->landlord, fn () => app(SignInLinks::class)->create($this->ngozi, TenantProfile::query()->findOrFail($this->tunde->id), InvitationChannel::Link));

    $handover = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm, $this->lagoon]);
    acceptHandover($this->ada, $this->agency, $handover);

    $links = collect(portalInvitationsOf($this->landlord))->mapWithKeys(fn (Invitation $link): array => [$link->tenant_profile_id => [$link->kind, $link->status]])->all();
    expect($links)->toBe([
        $this->kemi->id => [InvitationKind::Tenant, InvitationStatus::Cancelled],
        $this->femi->id => [InvitationKind::Tenant, InvitationStatus::Pending],
        $this->tunde->id => [InvitationKind::SignInLink, InvitationStatus::Cancelled],
    ]);

    $revoked = AuditEntry::query()->whereIn('event', ['tenant.portal_invitation_revoked', 'tenant.sign_in_link_revoked'])->orderBy('id')->get();
    expect($revoked->map(fn (AuditEntry $entry): array => [$entry->event, $entry->subject_id, $entry->properties['reason'], $entry->properties['handover_id']])->all())->toEqualCanonicalizing([
        ['tenant.portal_invitation_revoked', $this->kemi->id, 'moved', $handover->id],
        ['tenant.sign_in_link_revoked', $this->tunde->id, 'moved', $handover->id],
    ]);

    // Tunde keeps his portal account: his record at the agency carries it.
    expect(inOrganization($this->agency, fn () => TenantProfile::query()->where('phone', '+2348034445555')->sole()->user_id))->toBe($account->id)
        ->and(inOrganization($this->agency, fn () => Invitation::query()->whereIn('kind', [InvitationKind::Tenant, InvitationKind::SignInLink])->count()))->toBe(0);
});
