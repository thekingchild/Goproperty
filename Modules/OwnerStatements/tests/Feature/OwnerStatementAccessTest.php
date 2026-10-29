<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Models\User;
use App\Platform\Impersonation\ImpersonationMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Modules\OwnerStatements\Models\OwnerStatementSettlement;
use Modules\OwnerStatements\Models\OwnerStatementTerms;
use Modules\Properties\Models\OwnerProfile;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/statement-world.php';

/*
 * P08-05 ACs: an owner sees only their own buildings' figures, across two
 * agencies, and another owner's statement or month is 404; only the agency
 * (its owner or administrator) marks a month settled — a wrong role is
 * refused, the landlord cannot, GoProperty staff impersonating cannot —
 * and unsettling needs a reason; both are audited. All over HTTP and
 * Livewire update requests, each request fresh.
 */

beforeEach(function () {
    Notification::fake();
    statementWorld();
    setStatementTerms($this->acme, $this->owner, $this->ngoziAtAcme, ['commission' => '10']);
    $this->travelTo('2027-02-02 10:00:00');
});

afterEach(fn () => billsFreshRequest());

/** The ids a rendered page lists for data-$attribute. */
function statementIds(TestResponse $page, string $attribute): array
{
    return ownerPortalIds($page, $attribute);
}

describe('the owner portal', function () {
    it('acceptance: shows the owner her own buildings\' figures at both agencies, and nothing else', function () {
        $index = ownerPortalPage($this->ngozi, '/statements')->assertOk()
            ->assertSee('Adeyemi Realty')->assertSee('Bello Homes')
            ->assertSee('Commission: 10% of rent collected')->assertSee('Commission not set yet')
            ->assertDontSee('Cedar House')->assertDontSee('Harbour Point')->assertDontSee('Tunde Bakare');
        expect(statementIds($index, 'organization'))->toBe([$this->acme->id, $this->other->id])
            ->and(statementIds($index, 'owner'))->toBe([$this->ngoziAtAcme->id, $this->ngoziAtBello->id])
            ->and(substr_count((string) $index->getContent(), 'data-test="statement-month"'))->toBe(24)
            ->and((string) $index->getContent())->toContain('data-month="2027-01" data-status="unsettled" data-net-due="155300000"');

        $acme = ownerPortalPage($this->ngozi, "/statements/{$this->ngoziAtAcme->id}/2027-01")->assertOk()
            ->assertSee('Marina Heights')->assertSee('Palm Court')->assertSee('Bisi Adeyinka')->assertSee('₦1,553,000')
            // P03-16: the tenant's phone and email as the bill was issued, wherever the statement names the tenant.
            ->assertSee('data-test="statement-tenant-contacts"', false)->assertSee('+2348031230001')->assertSee('bisi.adeyinka@example.com')
            ->assertDontSee('Cedar House')->assertDontSee('Lagoon View')->assertDontSee('+2348034445555')->assertDontSee('tunde.bakare@example.com')
            ->assertDontSee('wire:click', false);
        expect(statementIds($acme, 'building'))->toBe([$this->palm->id, $this->marina->id])
            ->and((string) $acme->getContent())->toContain('data-net-due="155300000"');

        $bello = ownerPortalPage($this->ngozi, "/statements/{$this->ngoziAtBello->id}/2027-01")->assertOk()
            ->assertSee('Lagoon View')->assertSee('Commission not set: none taken')->assertDontSee('Palm Court')->assertDontSee('Harbour Point');
        expect(statementIds($bello, 'building'))->toBe([$this->lagoon->id])
            ->and((string) $bello->getContent())->toContain('data-net-due="90000000"');

        // From the owner portal's home.
        ownerPortalPage($this->ngozi)->assertOk()->assertSee('data-test="owner-statements-link"', false);
    });

    it('keeps what a month settled before P03-16 recorded: no tenant contacts there, contacts on months settled since', function () {
        $this->travelTo('2027-02-02 10:00:00');
        $settlement = settleStatement($this->acme, $this->owner, $this->ngoziAtAcme, '2027-01', '2027-02-01');
        $path = "/statements/{$this->ngoziAtAcme->id}/2027-01";

        // Settled now: the snapshot keeps the contacts the bills were issued with.
        ownerPortalPage($this->ngozi, $path)->assertOk()->assertSee('Settled')->assertSee('+2348031230001')->assertSee('bisi.adeyinka@example.com');

        // A month settled before P03-16 recorded names only (no contact keys): it shows what it recorded.
        $strip = function (mixed $value) use (&$strip): mixed {
            if (! is_array($value)) {
                return $value;
            }
            unset($value['tenant_phone'], $value['tenant_email']);

            return array_map($strip, $value);
        };
        $figures = json_decode((string) DB::table('owner_statement_settlements')->where('id', $settlement->id)->value('figures'), true);
        DB::table('owner_statement_settlements')->where('id', $settlement->id)->update(['figures' => json_encode($strip($figures))]);

        ownerPortalPage($this->ngozi, $path)->assertOk()->assertSee('Settled')->assertSee('Bisi Adeyinka')
            ->assertDontSee('+2348031230001')->assertDontSee('bisi.adeyinka@example.com')->assertDontSee('data-test="statement-tenant-contacts"', false);
    });

    it('acceptance: answers 404 for another owner\'s statements, months and PDFs', function (string $who, string $path) {
        $user = match ($who) {
            'ngozi' => $this->ngozi,
            'femi' => $this->femi,
            'agency owner' => $this->owner,
        };
        $owner = fn (string $name): int => match ($name) {
            'femi' => $this->femiAtAcme->id,
            'ngozi' => $this->ngoziAtAcme->id,
            'harbour' => inOrganization($this->other, fn () => OwnerProfile::query()->where('company_name', 'Harbour Holdings Ltd')->value('id')),
        };
        $path = preg_replace_callback('/\{(\w+)\}/', fn ($match) => (string) $owner($match[1]), $path);

        ownerPortalPage($user, $path)->assertNotFound()->assertDontSee('Marina Heights')->assertDontSee('Cedar House');
    })->with([
        'another owner\'s month' => ['ngozi', '/statements/{femi}/2027-01'],
        'another owner\'s PDF' => ['ngozi', '/statements/{femi}/2027-01/pdf'],
        'another organization\'s owner' => ['ngozi', '/statements/{harbour}/2027-01'],
        'her month, to the other owner' => ['femi', '/statements/{ngozi}/2027-01'],
        'her PDF, to the other owner' => ['femi', '/statements/{ngozi}/2027-01/pdf'],
        'her month, to the agency\'s owner' => ['agency owner', '/statements/{ngozi}/2027-01'],
        'a month that does not exist' => ['ngozi', '/statements/{ngozi}/2027-13'],
        'a month to come' => ['ngozi', '/statements/{ngozi}/2099-01'],
        'a missing owner' => ['ngozi', '/statements/999999/2027-01'],
    ]);

    it('shows the other owner only his own statement, and sends guests to sign in', function () {
        $femi = ownerPortalPage($this->femi, '/statements')->assertOk()->assertSee('Adeyemi Realty')->assertDontSee('Bello Homes');
        expect(statementIds($femi, 'owner'))->toBe([$this->femiAtAcme->id]);
        ownerPortalPage($this->femi, "/statements/{$this->femiAtAcme->id}/2027-01")->assertOk()->assertSee('Cedar House')->assertSee('₦1,800,000')->assertDontSee('Marina Heights');

        billsFreshRequest();
        auth()->logout();
        $this->get("/owner/statements/{$this->ngoziAtAcme->id}/2027-01")->assertRedirect('/login');
    });

    it('refuses every Livewire method call on the owner\'s statement pages, and changes nothing', function (string $page, string $method) {
        $path = $page === 'index' ? '/statements' : "/statements/{$this->ngoziAtAcme->id}/2027-01";

        ownerPortalCall($this->ngozi, $path, $method)->assertForbidden();
        expect(inOrganization($this->acme, fn () => OwnerStatementSettlement::query()->count()))->toBe(0);
    })->with([
        ['index', 'settle'], ['index', 'saveTerms'], ['month', 'settle'], ['month', 'unsettle'], ['month', 'mount'], ['month', '$set'],
    ]);

    it('lets the owner download the month\'s PDF, from her own statement only', function () {
        $pdf = ownerPortalPage($this->ngozi, "/statements/{$this->ngoziAtAcme->id}/2027-01/pdf")->assertOk();
        expect($pdf->headers->get('Content-Type'))->toBe('application/pdf')
            ->and((string) $pdf->headers->get('Content-Disposition'))->toContain('owner-statement-2027-01-ngozi-eze.pdf');
    });
});

describe('the agency', function () {
    it('acceptance: lets the organization\'s owner and administrators mark a month settled and unsettled, audited; never an agent or the landlord', function () {
        $admin = joinAs(User::factory()->create(['name' => 'Tola Admin']), $this->acme, OrganizationRole::Admin);
        $agent = joinAs(User::factory()->create(['name' => 'Bola Agent']), $this->acme, OrganizationRole::Agent);

        // The agent sees no statements at all; the landlord never opens the workspace.
        agencyStatementPage($agent, $this->ngoziAtAcme)->assertForbidden();
        agencyStatementPage($agent, $this->ngoziAtAcme, '/2027-01')->assertForbidden();
        agencyStatementPage($this->ngozi, $this->ngoziAtAcme)->assertNotFound();
        agencyStatementPage($this->ngozi, $this->ngoziAtAcme, '/2027-01')->assertNotFound();
        expect(fn () => settleStatement($this->acme, $agent, $this->ngoziAtAcme, '2027-01', '2027-02-02'))->toThrow(AuthorizationException::class)
            ->and(fn () => settleStatement($this->acme, $this->ngozi, $this->ngoziAtAcme, '2027-01', '2027-02-02'))->toThrow(AuthorizationException::class);

        // The administrator settles January from the month's page.
        agencyStatementPage($admin, $this->ngoziAtAcme, '/2027-01')->assertOk()->assertSee('data-test="settle-form"', false)->assertSee('₦1,553,000');
        agencyStatementCall($admin, $this->ngoziAtAcme, '/2027-01', 'settle', ['paidOn' => '2027-02-02', 'reference' => 'TRF-NGOZI-JAN'])->assertOk();

        $row = inOrganization($this->acme, fn () => OwnerStatementSettlement::query()->sole());
        expect($row->isSettled())->toBeTrue()
            ->and($row->paid_on?->toDateString())->toBe('2027-02-02')
            ->and($row->reference)->toBe('TRF-NGOZI-JAN')
            ->and($row->net_due_kobo)->toBe(155_300_000)
            ->and($row->settled_by_user_id)->toBe($admin->id)
            ->and($row->version)->toBe(1);

        agencyStatementPage($this->owner, $this->ngoziAtAcme)->assertOk()->assertSee('data-month="2027-01" data-status="settled"', false);
        ownerPortalPage($this->ngozi, "/statements/{$this->ngoziAtAcme->id}/2027-01")->assertOk()->assertSee('Settled: paid to the owner on 02/02/2027, reference TRF-NGOZI-JAN.');

        // Unsettling needs a reason.
        agencyStatementCall($this->owner, $this->ngoziAtAcme, '/2027-01', 'unsettle', ['reason' => ''])->assertOk();
        expect(fn () => unsettleStatement($this->acme, $this->owner, $this->ngoziAtAcme, '2027-01', '  '))->toThrow(ValidationException::class)
            ->and(inOrganization($this->acme, fn () => $row->fresh()?->isSettled()))->toBeTrue();
        agencyStatementCall($this->owner, $this->ngoziAtAcme, '/2027-01', 'unsettle', ['reason' => 'Transfer bounced; paying again'])->assertOk();

        $row = inOrganization($this->acme, fn () => $row->fresh());
        expect($row?->isSettled())->toBeFalse()
            ->and($row?->unsettle_reason)->toBe('Transfer bounced; paying again')
            ->and($row?->unsettled_by_user_id)->toBe($this->owner->id);

        $settled = AuditEntry::query()->where('event', 'owner_statement.settled')->sole();
        $unsettled = AuditEntry::query()->where('event', 'owner_statement.unsettled')->sole();
        expect($settled->causer_id)->toBe($admin->id)
            ->and($settled->getProperty('month'))->toBe('2027-01')
            ->and($settled->getProperty('reference'))->toBe('TRF-NGOZI-JAN')
            ->and($settled->getProperty('net_due_kobo'))->toBe(155_300_000)
            ->and($unsettled->causer_id)->toBe($this->owner->id)
            ->and($unsettled->getProperty('reason'))->toBe('Transfer bounced; paying again')
            ->and($unsettled->getProperty('was')['reference'] ?? null)->toBe('TRF-NGOZI-JAN');

        // Settled again: a new version (its own PDF).
        expect(settleStatement($this->acme, $this->owner, $this->ngoziAtAcme, '2027-01', '2027-02-02', 'TRF-NGOZI-JAN-2')->version)->toBe(2);
    });

    it('refuses to settle a month not yet over, a month settled already, and a date paid in the future or before the month', function () {
        expect(fn () => settleStatement($this->acme, $this->owner, $this->ngoziAtAcme, '2027-02', '2027-02-02'))->toThrow(ValidationException::class)
            ->and(fn () => settleStatement($this->acme, $this->owner, $this->ngoziAtAcme, '2027-01', '2027-02-03'))->toThrow(ValidationException::class)
            ->and(fn () => settleStatement($this->acme, $this->owner, $this->ngoziAtAcme, '2027-01', '2026-12-31'))->toThrow(ValidationException::class)
            ->and(fn () => settleStatement($this->acme, $this->owner, $this->ngoziAtAcme, '2027-01', '2027-02-01', ''))->toThrow(ValidationException::class);

        settleStatement($this->acme, $this->owner, $this->ngoziAtAcme, '2027-01', '2027-01-31');
        expect(fn () => settleStatement($this->acme, $this->owner, $this->ngoziAtAcme, '2027-01', '2027-02-01'))->toThrow(ValidationException::class)
            ->and(fn () => unsettleStatement($this->acme, $this->owner, $this->ngoziAtAcme, '2026-12'))->toThrow(ValidationException::class)
            ->and(inOrganization($this->acme, fn () => OwnerStatementSettlement::query()->count()))->toBe(1);

        agencyStatementPage($this->owner, $this->ngoziAtAcme, '/2027-02')->assertOk()->assertDontSee('data-test="settle-form"', false)->assertSee('once it has ended');
    });

    it('lets owners and administrators set the terms, checked and audited; agents never', function () {
        $agent = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent);

        // P03-16: the "share tenant contact details" switch is gone from the terms.
        agencyStatementPage($this->owner, $this->ngoziAtAcme)->assertOk()->assertSee('data-test="terms-commission"', false)->assertSee('data-test="export-owner_statements-csv"', false)
            ->assertDontSee('data-test="terms-contacts"', false)->assertDontSee('Share tenant contact details')->assertDontSee('Tenant contact details shared');
        agencyStatementCall($this->owner, $this->ngoziAtAcme, '', 'saveTerms', ['commission' => '12.5', 'utilitiesToOwner' => true])->assertOk();

        $terms = inOrganization($this->acme, fn () => OwnerStatementTerms::query()->where('owner_profile_id', $this->ngoziAtAcme->id)->sole());
        expect($terms->commission_bps)->toBe(1250)
            ->and($terms->utilities_to_owner)->toBeTrue()
            ->and(array_key_exists('share_tenant_contacts', $terms->getAttributes()))->toBeFalse()
            ->and(AuditEntry::query()->where('event', 'owner_statement.terms_changed')->latest('id')->first()?->getProperty('after'))->toEqual(['commission_bps' => 1250, 'utilities_to_owner' => true]);

        // A caller still sending the old key is not refused; the key changes nothing (nothing new to audit).
        $audited = AuditEntry::query()->where('event', 'owner_statement.terms_changed')->count();
        setStatementTerms($this->acme, $this->owner, $this->ngoziAtAcme, ['commission' => '12.5', 'utilitiesToOwner' => true, 'shareTenantContacts' => false]);
        expect(AuditEntry::query()->where('event', 'owner_statement.terms_changed')->count())->toBe($audited);

        foreach (['101', 'ten', '10.555', '-5'] as $bad) {
            expect(fn () => setStatementTerms($this->acme, $this->owner, $this->ngoziAtAcme, ['commission' => $bad]))->toThrow(ValidationException::class);
        }

        expect(fn () => setStatementTerms($this->acme, $agent, $this->ngoziAtAcme, ['commission' => '50']))->toThrow(AuthorizationException::class);
        // Blank: not set again.
        setStatementTerms($this->acme, $this->owner, $this->ngoziAtAcme, ['commission' => '']);
        expect(statementOf($this->acme, $this->ngoziAtAcme, '2027-01')->statement->commissionSet())->toBeFalse();
    });

    it('acceptance: blocks settling, unsettling and the terms while GoProperty staff impersonate, and audits the attempt', function () {
        seedStaffRoles();
        $staff = staffMember(['name' => 'Ada Okafor', 'email' => 'ada@goproperty.ng']);
        settleStatement($this->acme, $this->owner, $this->ngoziAtAcme, '2026-12', '2027-01-05', 'TRF-DEC');
        $handOff = startSupport($staff, $this->owner, $this->acme, ImpersonationMode::Write);
        enterSupport($handOff)->assertRedirect('/o/acme-homes');

        $january = LivewireHttp::snapshot(asSupport()->get("/o/acme-homes/owners/{$this->ngoziAtAcme->id}/statements/2027-01")->assertOk()->assertSee('₦1,553,000')->assertDontSee('data-test="settle-form"', false));
        freshRequest();
        LivewireHttp::update(test(), $january, ['paidOn' => '2027-02-02', 'reference' => 'BY-SUPPORT'], [['settle']])->assertForbidden();
        $december = LivewireHttp::snapshot(asSupport()->get("/o/acme-homes/owners/{$this->ngoziAtAcme->id}/statements/2026-12")->assertOk()->assertDontSee('data-test="unsettle-form"', false));
        freshRequest();
        LivewireHttp::update(test(), $december, ['reason' => 'By support'], [['unsettle']])->assertForbidden();
        $index = LivewireHttp::snapshot(asSupport()->get("/o/acme-homes/owners/{$this->ngoziAtAcme->id}/statements")->assertOk()->assertDontSee('data-test="terms-commission"', false));
        freshRequest();
        LivewireHttp::update(test(), $index, ['commission' => '50'], [['saveTerms']])->assertForbidden();

        expect(inOrganization($this->acme, fn () => OwnerStatementSettlement::query()->where('status', 'settled')->pluck('reference')->all()))->toBe(['TRF-DEC'])
            ->and(inOrganization($this->acme, fn () => OwnerStatementTerms::query()->where('owner_profile_id', $this->ngoziAtAcme->id)->value('commission_bps')))->toBe(1000)
            ->and(AuditEntry::query()->where('event', 'impersonation.blocked')->where('subject_id', $handOff->session->id)->count())->toBeGreaterThanOrEqual(3);
    });

    it('keeps statements read-only while the organization is suspended', function () {
        $page = LivewireHttp::snapshot(agencyStatementPage($this->owner, $this->ngoziAtAcme, '/2027-01')->assertOk());
        $this->acme->forceFill(['status' => OrganizationStatus::Suspended])->save();

        agencyStatementPage($this->owner, $this->ngoziAtAcme, '/2027-01')->assertOk()->assertDontSee('data-test="settle-form"', false);
        billsFreshRequest();
        $this->actingAs($this->owner);
        LivewireHttp::update(test(), $page, ['paidOn' => '2027-02-02', 'reference' => 'TRF'], [['settle']])->assertForbidden();
        expect(fn () => settleStatement($this->acme, $this->owner, $this->ngoziAtAcme, '2027-01', '2027-02-02'))->toThrow(AuthorizationException::class);
    });

    it('answers 404 for another organization\'s owner, and a member of another organization', function () {
        agencyStatementPage($this->owner, $this->ngoziAtBello)->assertNotFound();
        agencyStatementPage($this->owner, $this->ngoziAtBello, '/2027-01')->assertNotFound();
        agencyStatementPage($this->owner, $this->ngoziAtBello, '/2027-01/pdf')->assertNotFound();
        agencyStatementPage($this->otherOwner, $this->ngoziAtAcme, '', 'other-realty')->assertNotFound();
        agencyStatementPage($this->otherOwner, $this->ngoziAtAcme, '', 'acme-homes')->assertNotFound();
        agencyStatementPage($this->owner, $this->ngoziAtAcme, '/2027-13')->assertNotFound();
        expect(fn () => settleStatement($this->acme, $this->owner, $this->ngoziAtBello, '2027-01', '2027-02-02'))->toThrow(AuthorizationException::class)
            ->and(inOrganization($this->other, fn () => OwnerStatementSettlement::query()->count()))->toBe(0);
    });

    it('links the statements from the owner\'s page for owners and administrators', function () {
        billsFreshRequest();
        $this->actingAs($this->owner)->get("/o/acme-homes/owners/{$this->ngoziAtAcme->id}")->assertOk()->assertSee('data-test="owner-statements-link"', false);
        $agent = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent);
        billsFreshRequest();
        $this->actingAs($agent)->get("/o/acme-homes/owners/{$this->ngoziAtAcme->id}")->assertOk()->assertDontSee('data-test="owner-statements-link"', false);
    });
});
