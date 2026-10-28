<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\OrganizationRole;
use App\Models\User;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Modules\Handovers\Enums\CreditSettlement;
use Modules\Handovers\Handovers\HandoverCredits;
use Modules\Handovers\Handovers\HandoverRefused;
use Modules\Handovers\Models\ManagementHandover;
use Modules\Handovers\Notifications\HandoverChanged;
use Modules\Tenants\Models\TenantProfile;

require_once __DIR__.'/helpers.php';

/*
 * P03 decision 11 (product owner, settled): tenant credit at a handover is
 * settled per tenant. The handover lists each moving tenant's unused credit
 * and unpaid bills; the landlord chooses "pass to the agency" (default; the
 * agency records opening credit only after confirming the money arrived
 * outside GoProperty) or "refund the tenant" (a recorded refund closes the
 * credit); unsettled credit stays on the landlord's books, visible to
 * both, never moved silently or counted twice.
 *
 * In the world, Tunde paid ₦100,000 more than his first bill (credit);
 * Kemi owes her first rent bill and the security levy; Musa owes his rent;
 * Bisi's lease is a draft.
 */

beforeEach(function () {
    handoverWorld();
    $this->handover = offerHandover($this->ngozi, $this->landlord, $this->agency, [$this->palm, $this->lagoon]);
});

afterEach(fn () => handoversFreshRequest());

/** The agency's record of a moving tenant (by phone). */
function agencyTenant(string $phone): TenantProfile
{
    return inOrganization(test()->agency, fn () => TenantProfile::query()->where('phone', $phone)->sole());
}

function chooseSettlement(User $by, $organization, ManagementHandover $handover, int $line, CreditSettlement $choice): void
{
    inOrganization($organization, fn () => app(HandoverCredits::class)->choose($by, storedHandover($handover), $line, $choice));
}

function recordCreditRefund(User $by, $organization, ManagementHandover $handover, int $line, Money $shown): void
{
    inOrganization($organization, fn () => app(HandoverCredits::class)->refund($by, storedHandover($handover), $line, $shown));
}

function confirmCreditArrived(User $by, $organization, ManagementHandover $handover, int $line, Money $shown): void
{
    $handover = $organization->id === $handover->to_organization_id ? incomingHandover($organization, $handover) : storedHandover($handover);
    inOrganization($organization, fn () => app(HandoverCredits::class)->confirmArrived($by, $handover, $line, $shown));
}

it('lists each moving tenant with their unused credit and unpaid bills, the landlord\'s choice defaulting to "pass to the agency"', function () {
    $lines = storedHandover($this->handover)->tenants->keyBy('tenant_profile_id');

    expect($lines->keys()->sort()->values()->all())->toBe([$this->tunde->id, $this->kemi->id, $this->musa->id, $this->bisi->id])
        ->and($lines[$this->tunde->id]->tenant_name)->toBe('Tunde Bakare')
        ->and($lines[$this->tunde->id]->credit_kobo)->toEqual(Money::naira(100_000))
        ->and($lines[$this->tunde->id]->unpaid_bills)->toBe(0)
        ->and($lines[$this->kemi->id]->credit_kobo)->toEqual(Money::zero())
        ->and($lines[$this->kemi->id]->unpaid_bills)->toBe(2)
        ->and($lines[$this->kemi->id]->unpaid_kobo)->toEqual(Money::naira(1_225_000))
        ->and($lines[$this->musa->id]->unpaid_kobo)->toEqual(Money::naira(900_000))
        ->and($lines[$this->bisi->id]->unpaid_bills)->toBe(0)
        ->and($lines->pluck('settlement')->unique()->all())->toBe([CreditSettlement::PassToAgency]);

    // The accept screen shows them to the agency.
    handoversPage($this->ada, 'acme-homes', "/{$this->handover->id}")->assertOk()
        ->assertSeeInOrder(['Tunde Bakare', 'Credit: ₦100,000', 'Pass to the agency'])
        ->assertSee('2 unpaid bills (₦1,225,000)')
        ->assertSee('Musa Danjuma')
        ->assertDontSee('Femi Okafor');
});

it('leaves the credit on the landlord\'s books at acceptance, visible to both, and never moves it silently', function () {
    acceptHandover($this->ada, $this->agency, $this->handover);

    expect(creditOf($this->landlord, $this->tunde))->toEqual(Money::naira(100_000))
        ->and(creditOf($this->agency, agencyTenant('+2348034445555')))->toEqual(Money::zero())
        ->and(creditLine($this->handover, $this->tunde->id)->receiving_tenant_profile_id)->toBe(agencyTenant('+2348034445555')->id)
        ->and(creditLine($this->handover, $this->tunde->id)->settled_at)->toBeNull();

    foreach ([[$this->ngozi, 'eze-properties'], [$this->ada, 'acme-homes']] as [$owner, $slug]) {
        handoversPage($owner, $slug, "/{$this->handover->id}")->assertOk()->assertSeeInOrder(['Tunde Bakare', 'Credit: ₦100,000']);
    }
});

it('passes the credit to the agency once it confirms the money arrived: closed on the landlord\'s books, opened on the agency\'s, in one step', function () {
    acceptHandover($this->ada, $this->agency, $this->handover);
    $line = creditLine($this->handover, $this->tunde->id);
    $agencyTunde = agencyTenant('+2348034445555');

    // Only the agency confirms, and only the amount on the landlord's books.
    expect(fn () => confirmCreditArrived($this->ngozi, $this->landlord, $this->handover, $line->id, Money::naira(100_000)))->toThrow(HandoverRefused::class, 'Only the agency')
        ->and(fn () => confirmCreditArrived($this->ada, $this->agency, $this->handover, $line->id, Money::naira(90_000)))->toThrow(HandoverRefused::class, 'now ₦100,000')
        ->and(creditOf($this->landlord, $this->tunde))->toEqual(Money::naira(100_000))
        ->and(creditOf($this->agency, $agencyTunde))->toEqual(Money::zero());

    Notification::fake();
    confirmCreditArrived($this->ada, $this->agency, $this->handover, $line->id, Money::naira(100_000));

    expect(creditOf($this->landlord, $this->tunde))->toEqual(Money::zero())
        ->and(creditOf($this->agency, $agencyTunde))->toEqual(Money::naira(100_000))
        ->and(array_slice(creditEntriesOf($this->landlord, $this->tunde), -1))->toBe([['handed_over', -10_000_000, null]])
        ->and(creditEntriesOf($this->agency, $agencyTunde))->toBe([['brought_over', 10_000_000, null]]);

    $line = creditLine($this->handover, $this->tunde->id);
    expect($line->settled_kobo)->toEqual(Money::naira(100_000))
        ->and($line->settled_by_user_id)->toBe($this->ada->id)
        ->and($line->settled_by_organization_id)->toBe($this->agency->id)
        ->and(AuditEntry::query()->where('event', 'handover.credit_passed_on')->sole()->causer_id)->toBe($this->ada->id);

    foreach ([$this->ngozi, $this->ada] as $owner) {
        Notification::assertSentToTimes($owner, HandoverChanged::class, 1);
    }

    // Never counted twice: settled once, and the page says so on both sides.
    expect(fn () => confirmCreditArrived($this->ada, $this->agency, $this->handover, $line->id, Money::naira(100_000)))->toThrow(HandoverRefused::class, 'already settled')
        ->and(creditOf($this->agency, $agencyTunde))->toEqual(Money::naira(100_000));
    handoversPage($this->ngozi, 'eze-properties', "/{$this->handover->id}")->assertOk()->assertSee('₦100,000 passed to Adeyemi Realty Ltd on');
});

it('lets the landlord refund the tenant instead: the recorded refund closes the credit, and the agency gets none', function () {
    acceptHandover($this->ada, $this->agency, $this->handover);
    $line = creditLine($this->handover, $this->tunde->id);

    // The landlord must choose "refund" first; the agency never chooses.
    expect(fn () => recordCreditRefund($this->ngozi, $this->landlord, $this->handover, $line->id, Money::naira(100_000)))->toThrow(HandoverRefused::class, 'Choose "Refund the tenant" first')
        ->and(fn () => chooseSettlement($this->ada, $this->agency, $this->handover, $line->id, CreditSettlement::Refund))->toThrow(HandoverRefused::class, 'Only the landlord');

    chooseSettlement($this->ngozi, $this->landlord, $this->handover, $line->id, CreditSettlement::Refund);
    expect(creditLine($this->handover, $this->tunde->id)->settlement)->toBe(CreditSettlement::Refund)
        ->and(fn () => confirmCreditArrived($this->ada, $this->agency, $this->handover, $line->id, Money::naira(100_000)))->toThrow(HandoverRefused::class, 'chose to refund');

    recordCreditRefund($this->ngozi, $this->landlord, $this->handover, $line->id, Money::naira(100_000));

    expect(creditOf($this->landlord, $this->tunde))->toEqual(Money::zero())
        ->and(array_slice(creditEntriesOf($this->landlord, $this->tunde), -1))->toBe([['refund', -10_000_000, null]])
        ->and(creditOf($this->agency, agencyTenant('+2348034445555')))->toEqual(Money::zero())
        ->and(creditLine($this->handover, $this->tunde->id)->settled_by_organization_id)->toBe($this->landlord->id)
        ->and(AuditEntry::query()->where('event', 'handover.credit_refunded')->sole()->causer_id)->toBe($this->ngozi->id)
        ->and(fn () => chooseSettlement($this->ngozi, $this->landlord, $this->handover, $line->id, CreditSettlement::PassToAgency))->toThrow(HandoverRefused::class, 'already settled');
});

it('settles nothing before the handover is accepted, and keeps the landlord\'s choice through acceptance', function () {
    $line = creditLine($this->handover, $this->tunde->id);
    chooseSettlement($this->ngozi, $this->landlord, $this->handover, $line->id, CreditSettlement::Refund);

    expect(fn () => recordCreditRefund($this->ngozi, $this->landlord, $this->handover, $line->id, Money::naira(100_000)))->toThrow(HandoverRefused::class, 'once the handover is accepted')
        ->and(creditOf($this->landlord, $this->tunde))->toEqual(Money::naira(100_000));

    acceptHandover($this->ada, $this->agency, $this->handover);
    expect(creditLine($this->handover, $this->tunde->id)->settlement)->toBe(CreditSettlement::Refund);
});

it('lets only owners settle credit, never while GoProperty staff impersonate', function (OrganizationRole $role) {
    acceptHandover($this->ada, $this->agency, $this->handover);
    $line = creditLine($this->handover, $this->tunde->id);
    $member = joinAs(User::factory()->create(), $this->agency, $role);
    $landlordMember = joinAs(User::factory()->create(), $this->landlord, $role);

    expect(fn () => confirmCreditArrived($member, $this->agency, $this->handover, $line->id, Money::naira(100_000)))->toThrow(AuthorizationException::class)
        ->and(fn () => chooseSettlement($landlordMember, $this->landlord, $this->handover, $line->id, CreditSettlement::Refund))->toThrow(AuthorizationException::class)
        ->and(creditOf($this->landlord, $this->tunde))->toEqual(Money::naira(100_000));
})->with([
    'administrator' => [OrganizationRole::Admin],
    'agent' => [OrganizationRole::Agent],
]);
