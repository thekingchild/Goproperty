<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Models\Organization;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Modules\Leasing\Enums\LeaseStatus;
use Modules\Leasing\Leases\LeaseService;
use Modules\Leasing\Models\Lease;
use Modules\Properties\Enums\UnitStatus;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../../../../tests/Feature/Exports/helpers.php';

/*
 * P03-03a: the lease pages (list with filters and export, new lease and
 * draft edit, renewal, the lease page and its actions), through HTTP and
 * Livewire updates as the browser sends them.
 */

beforeEach(function () {
    seedOrganizationRoles();
    $this->travelTo('2026-12-20 10:00:00');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $this->owner = joinAs(User::factory()->create(), $this->acme);
    $this->palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court']);
    $this->oak = buildingIn($this->acme, ['name' => 'Oak Lodge', 'slug' => 'oak-lodge']);
    $this->flat = unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 1', 'base_rent_kobo' => Money::naira(1_800_000)]);
    $this->kemi = tenantIn($this->acme, ['first_name' => 'Kemi', 'last_name' => 'Adebayo', 'phone' => '+2348031234567']);
});

afterEach(fn () => leasesFreshRequest());

it('drafts a lease from the form: building, then unit, rent from the unit, held for the tenant', function () {
    unitIn($this->acme, $this->oak, ['unit_label' => 'Oak 1']);
    $form = leasesSnapshot($this->owner, '/create');
    leasesPage($this->owner, '/create')->assertSee('Kemi Adebayo (+2348031234567)')->assertSee('Palm Court')->assertSee('Oak Lodge');

    // Choosing the building lists its units, with their status.
    $chosen = leasesUpdate($this->owner, $form, ['propertyId' => (string) $this->palm->id])->assertOk();
    expect(LivewireHttp::html($chosen))->toContain('Flat 1 (Vacant)')->not->toContain('Oak 1');

    // Choosing the unit fills a yearly lease's rent from the unit's asking rent.
    $unit = leasesUpdate($this->owner, LivewireHttp::next($chosen), ['unitId' => (string) $this->flat->id])->assertOk();
    expect(LivewireHttp::next($unit))->toContain('"rent":"1,800,000"');

    $saved = leasesUpdate($this->owner, LivewireHttp::next($unit), ['tenantId' => (string) $this->kemi->id, 'startDate' => '2027-01-01', 'endDate' => '2027-12-31', 'deposit' => '180,000'], [['save']])->assertOk();
    $lease = leasesOf($this->acme)->sole();

    expect(LivewireHttp::redirect($saved))->toBe("http://localhost/o/acme-homes/leases/{$lease->id}")
        ->and($lease->status)->toBe(LeaseStatus::Draft)
        ->and($lease->rent_amount_kobo->kobo)->toBe(180_000_000)
        ->and($lease->deposit_kobo->kobo)->toBe(18_000_000)
        ->and(unitStatusOf($this->acme, $this->flat))->toBe(UnitStatus::Reserved);

    leasesPage($this->owner, "/{$lease->id}")->assertOk()->assertSee('Kemi Adebayo · Flat 1')->assertSee('data-test="reserved-until"', false)->assertSee('03/01/2027')->assertSee('Given when the lease is activated');
});

it('shows the form\'s errors and saves nothing', function () {
    $response = leasesUpdate($this->owner, leasesSnapshot($this->owner, '/create'), ['tenantId' => '', 'unitId' => ''] + leaseInput(['endDate' => '2026-01-01', 'rent' => '']), [['save']])->assertOk();

    expect(LivewireHttp::errors($response))->toHaveKeys(['tenantId', 'unitId'])
        ->and(leasesOf($this->acme))->toHaveCount(0);

    // The building is chosen first, as on the page (choosing it clears the unit).
    $building = leasesUpdate($this->owner, leasesSnapshot($this->owner, '/create'), ['propertyId' => (string) $this->palm->id])->assertOk();
    $response = leasesUpdate($this->owner, LivewireHttp::next($building), ['tenantId' => (string) $this->kemi->id, 'unitId' => (string) $this->flat->id] + leaseInput(['endDate' => '2026-01-01', 'rent' => 'a lot']), [['save']])->assertOk();

    expect(LivewireHttp::errors($response))->toHaveKeys(['endDate', 'rent'])
        ->and(leasesOf($this->acme))->toHaveCount(0)
        ->and(unitStatusOf($this->acme, $this->flat))->toBe(UnitStatus::Vacant);
});

it('opens the form for a unit or a tenant from their pages', function () {
    $form = json_decode(leasesSnapshot($this->owner, "/create?unit={$this->flat->id}&tenant={$this->kemi->id}"), true)['data'];

    expect($form['unitId'])->toBe((string) $this->flat->id)
        ->and($form['propertyId'])->toBe((string) $this->palm->id)
        ->and($form['tenantId'])->toBe((string) $this->kemi->id)
        ->and($form['rent'])->toBe('1,800,000');

    tenantsPage($this->owner, "/{$this->kemi->id}")->assertOk()->assertSee("leases/create?tenant={$this->kemi->id}", false);
});

it('changes a draft\'s terms on its edit page, and the edit page closes once the lease is active', function () {
    $lease = draftLease($this->acme, $this->owner, $this->flat, $this->kemi);

    leasesPage($this->owner, "/{$lease->id}/edit")->assertOk()->assertSee('data-test="fixed-tenant"', false)->assertSee('Kemi Adebayo');
    leasesUpdate($this->owner, leasesSnapshot($this->owner, "/{$lease->id}/edit"), ['rent' => '1,750,000', 'billingCycle' => 'semi_annual'], [['save']])->assertOk();

    $stored = storedLease($this->acme, $lease);
    expect($stored->rent_amount_kobo->kobo)->toBe(175_000_000)
        ->and($stored->billing_cycle->value)->toBe('semi_annual')
        ->and($stored->tenant_profile_id)->toBe($this->kemi->id);

    inOrganization($this->acme, fn () => app(LeaseService::class)->activate($this->owner, $stored));
    leasesPage($this->owner, "/{$lease->id}/edit")->assertForbidden();
});

it('renews from the lease page: the next term starts the day after, for as long, deposit carried', function () {
    $lease = activeLease($this->acme, $this->owner, $this->flat, $this->kemi, ['startDate' => '2026-03-01', 'endDate' => '2027-02-28']);

    leasesPage($this->owner, "/{$lease->id}")->assertSee('data-test="renew-lease"', false);
    $page = leasesPage($this->owner, "/{$lease->id}/renew")->assertOk()->assertSee('₦150,000 carried over from the current lease.');
    $form = json_decode(LivewireHttp::snapshot($page), true)['data'];

    expect($form['startDate'])->toBe('2027-03-01')
        ->and($form['endDate'])->toBe('2028-02-29')
        ->and($form['rent'])->toBe('1,500,000');

    $saved = leasesUpdate($this->owner, LivewireHttp::snapshot($page), ['rent' => '1,650,000'], [['save']])->assertOk();
    $renewal = leasesOf($this->acme)->last();

    expect(LivewireHttp::redirect($saved))->toBe("http://localhost/o/acme-homes/leases/{$renewal->id}")
        ->and($renewal->renewal_of_lease_id)->toBe($lease->id)
        ->and($renewal->rent_amount_kobo->kobo)->toBe(165_000_000)
        ->and($renewal->deposit_kobo->kobo)->toBe(15_000_000);

    // One renewal at a time: the button goes away.
    leasesPage($this->owner, "/{$lease->id}")->assertDontSee('data-test="renew-lease"', false)->assertSee('data-test="renewed-by"', false);
});

it('activates, ends and settles a lease from its page, and shows a refused move', function () {
    $lease = draftLease($this->acme, $this->owner, $this->flat, $this->kemi);
    $other = draftLease($this->acme, $this->owner, $this->flat, tenantIn($this->acme, ['first_name' => 'Bola', 'last_name' => 'Ade']));

    $activated = leasesUpdate($this->owner, leasesSnapshot($this->owner, "/{$lease->id}"), [], [['activate']])->assertOk();
    expect(LivewireHttp::html($activated))->toContain('The lease is active: LS-ADR-2026-000001.')->toContain('data-test="contract-number">LS-ADR-2026-000001');

    // The other draft waits for the unit: activating it is refused, with the reason.
    $refused = leasesUpdate($this->owner, leasesSnapshot($this->owner, "/{$other->id}"), [], [['activate']])->assertOk();
    expect(LivewireHttp::html($refused))->toContain('data-test="lease-warning"')->toContain('Flat 1 is already let (LS-ADR-2026-000001).')
        ->and(storedLease($this->acme, $other)->status)->toBe(LeaseStatus::Draft);

    // Ending needs a reason.
    $page = leasesSnapshot($this->owner, "/{$lease->id}");
    expect(LivewireHttp::errors(leasesUpdate($this->owner, $page, ['reason' => ''], [['terminate']])->assertOk()))->toHaveKey('reason');
    leasesUpdate($this->owner, $page, ['reason' => 'Relocated to Abuja'], [['terminate']])->assertOk();

    expect(storedLease($this->acme, $lease)->status)->toBe(LeaseStatus::Terminated)
        ->and(storedLease($this->acme, $lease)->termination_reason)->toBe('Relocated to Abuja')
        // Bola's draft still waits: the unit is reserved for it.
        ->and(unitStatusOf($this->acme, $this->flat))->toBe(UnitStatus::Reserved);

    $ended = leasesPage($this->owner, "/{$lease->id}")->assertOk()->assertSee('data-test="settle-deposit"', false)->assertSee('Held now: ₦150,000');
    leasesUpdate($this->owner, LivewireHttp::snapshot($ended), ['refunded' => '150,000'], [['settleDeposit']])->assertOk();

    leasesPage($this->owner, "/{$lease->id}")->assertSee('Refunded to the tenant')->assertSee('Held now: ₦0')->assertDontSee('data-test="settle-deposit"', false);
});

it('lists leases 25 a page with a debounced search and filters kept in the URL', function () {
    $tenants = [];

    foreach (range(1, 27) as $n) {
        $unit = unitIn($this->acme, $n % 2 === 0 ? $this->oak : $this->palm, ['unit_label' => "Unit {$n}"]);
        $tenants[$n] = tenantIn($this->acme, ['first_name' => "Tenant{$n}", 'last_name' => 'Okafor']);
        $n % 3 === 0
            ? activeLease($this->acme, $this->owner, $unit, $tenants[$n], ['startDate' => '2027-01-'.str_pad((string) $n, 2, '0', STR_PAD_LEFT)])
            : draftLease($this->acme, $this->owner, $unit, $tenants[$n], ['startDate' => '2027-01-'.str_pad((string) $n, 2, '0', STR_PAD_LEFT), 'billingCycle' => 'monthly']);
    }

    $all = leasesOf($this->acme)->sortByDesc(fn (Lease $lease) => $lease->start_date->toDateString().str_pad((string) $lease->id, 9, '0', STR_PAD_LEFT))->pluck('id')->values()->all();

    expect(listedLeases(leasesPage($this->owner)))->toBe(array_slice($all, 0, 25))
        ->and(listedLeases(leasesPage($this->owner, '?page=2')))->toBe(array_slice($all, 25))
        ->and(listedLeases(leasesPage($this->owner, '?status=active')))->toHaveCount(9)
        ->and(listedLeases(leasesPage($this->owner, '?building='.$this->oak->id)))->toHaveCount(13)
        ->and(listedLeases(leasesPage($this->owner, '?cycle=monthly')))->toHaveCount(18)
        ->and(listedLeases(leasesPage($this->owner, '?q=Tenant12')))->toBe([leasesOf($this->acme)->firstWhere('tenant_profile_id', $tenants[12]->id)->id])
        ->and(listedLeases(leasesPage($this->owner, '?q=LS-ADR-2026-000002')))->toHaveCount(1)
        ->and(listedLeases(leasesPage($this->owner, '?status=bogus')))->toHaveCount(25);

    // Typed on the page: back to page one, filters cleared in one go.
    $snapshot = leasesSnapshot($this->owner, '?page=2');
    $searched = leasesUpdate($this->owner, $snapshot, ['search' => 'Tenant3'])->assertOk();
    expect(listedLeases($searched))->toHaveCount(1);
    $cleared = leasesUpdate($this->owner, LivewireHttp::next($searched), [], [['clearFilters']])->assertOk();
    expect(listedLeases($cleared))->toBe(array_slice($all, 0, 25));
});

it('loads a page of leases without a query per lease (no N+1)', function () {
    $count = function (): int {
        $queries = 0;
        DB::listen(function ($query) use (&$queries): void {
            $queries += preg_match('/\b(leases|units|properties|tenant_profiles)\b/', $query->sql) === 1 ? 1 : 0;
        });
        leasesPage($this->owner)->assertOk();

        return $queries;
    };

    activeLease($this->acme, $this->owner, $this->flat, $this->kemi);
    $one = $count();
    expect($one)->toBeGreaterThan(3);

    foreach (range(1, 20) as $n) {
        draftLease($this->acme, $this->owner, unitIn($this->acme, $n % 2 === 0 ? $this->oak : $this->palm), tenantIn($this->acme));
    }

    expect($count())->toBe($one);
});

it('shows empty states, and the New lease button only to members who may draft one', function () {
    leasesPage($this->owner)->assertOk()->assertSee('No leases yet')->assertSee('data-test="add-lease"', false);
    leasesPage($this->owner, '?q=nobody')->assertOk()->assertSee('No lease matches')->assertSee('data-test="clear-filters"', false);
});

it('exports the leases the page shows, in Excel and CSV, with its filters, audited', function () {
    $lease = activeLease($this->acme, $this->owner, $this->flat, $this->kemi);
    $draft = draftLease($this->acme, $this->owner, unitIn($this->acme, $this->oak, ['unit_label' => 'Oak 1']), tenantIn($this->acme, ['first_name' => 'Bola', 'last_name' => 'Ade']), ['billingCycle' => 'monthly', 'rent' => '150,000']);
    $renewal = inOrganization($this->acme, fn () => app(LeaseService::class)->renew($this->owner, $lease, leaseInput(['startDate' => '2028-01-01', 'endDate' => '2028-12-31'])));

    foreach (['csv', 'xlsx'] as $format) {
        $rows = exportRows($this->owner, '/leases', 'leases', $format);

        expect($rows[0])->toBe(['Contract number', 'Status', 'Tenant', 'Building', 'Unit', 'Billing cycle', 'Start date', 'End date', 'Rent per period', 'Deposit', 'Due days after period start', 'Paid until', 'Next billing date', 'Auto-renew', 'Renewal of', 'Unit held until', 'Activated on', 'Ended on', 'Reason ended', 'Added on'])
            ->and(count($rows))->toBe(4)
            // Newest start first: the renewal (2028), then the two 2027 leases, newest id first.
            ->and($rows[1])->toBe(['', 'Draft', 'Kemi Adebayo', 'Palm Court', 'Flat 1', 'Yearly', '2028-01-01', '2028-12-31', '1500000.00', '150000.00', '0', '', '', 'No', 'LS-ADR-2026-000001', '2027-01-03', '', '', '', '2026-12-20 11:00'])
            ->and($rows[2])->toBe(['', 'Draft', 'Bola Ade', 'Oak Lodge', 'Oak 1', 'Monthly', '2027-01-01', '2027-12-31', '150000.00', '150000.00', '0', '', '', 'No', '', '2027-01-03', '', '', '', '2026-12-20 11:00'])
            ->and($rows[3])->toBe(['LS-ADR-2026-000001', 'Active', 'Kemi Adebayo', 'Palm Court', 'Flat 1', 'Yearly', '2027-01-01', '2027-12-31', '1500000.00', '150000.00', '0', '', '2027-01-01', 'No', '', '', '2026-12-20 11:00', '', '', '2026-12-20 11:00']);
    }

    // The page's filters, typed or from the URL.
    expect(array_column(array_slice(exportRows($this->owner, '/leases?status=active', 'leases'), 1), 0))->toBe(['LS-ADR-2026-000001'])
        ->and(array_column(array_slice(exportRows($this->owner, '/leases', 'leases', 'csv', ['building' => (string) $this->oak->id]), 1), 4))->toBe(['Oak 1']);

    $audit = AuditEntry::query()->where('log_name', 'exports')->latest('id')->first();
    expect($audit->properties['list'])->toBe('leases')
        ->and($audit->causer_id)->toBe($this->owner->id);

    unset($draft, $renewal);
});
