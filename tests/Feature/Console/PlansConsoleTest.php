<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Models\Organization;
use App\Models\PlanVersion;
use App\Models\SubscriptionPlan;
use App\Platform\Filament\Resources\Plans\Pages\EditPlan;
use App\Platform\Filament\Resources\Plans\Pages\ListPlans;
use App\Platform\Filament\Resources\Plans\RelationManagers\VersionsRelationManager;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

require_once __DIR__.'/../../Support/plans.php';

/*
 * P06-01a: Console → Billing → Plans & prices (PLATFORM_CONSOLE.md § 1A:
 * finance-admin, `plans.manage`). Details change at once; new terms are
 * scheduled as a version, at least 30 days away when customers are on the
 * plan; versions are listed with their state and a scheduled one can be
 * cancelled. The landing page reads the live plans.
 */

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('console'));
    Notification::fake();
    seedStaffRoles();
    $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00', 'Africa/Lagos'));
    $this->console = '/'.config('app.admin_path');
    $this->finance = staffNamed('Funmi', ['finance-admin']);
    $this->growth = SubscriptionPlan::query()->where('key', 'growth')->sole();
    activeSubscription(Organization::factory()->create(), 'growth');
});

it('opens the plans for plans.manage only', function (string $role, int $status) {
    $this->actingAs(staffNamed('Someone', [$role]), 'staff');

    $this->get($this->console.'/plans')->assertStatus($status);
    $this->get($this->console.'/plans/'.$this->growth->id.'/edit')->assertStatus($status);
})->with([
    'finance-admin' => ['finance-admin', 200],
    'super-admin' => ['super-admin', 200],
    'operations-manager' => ['operations-manager', 403],
    'support-agent' => ['support-agent', 403],
    'content-manager' => ['content-manager', 403],
    'analyst' => ['analyst', 403],
]);

it('lists each plan with today\'s prices, its customers and any scheduled change', function () {
    $this->actingAs($this->finance, 'staff');

    $this->get($this->console.'/plans')->assertOk()
        ->assertSeeInOrder(['Starter', '₦10,000 a month, 10 units + ₦700 each extra unit', 'Growth', '₦35,000 a month, 50 units + ₦500 each extra unit', '1', 'Scale', 'Enterprise', 'from ₦250,000 a month, 1,000 units + custom']);
});

it('saves the details at once, audited, and the landing page shows them', function () {
    $this->actingAs($this->finance, 'staff');

    Livewire::test(EditPlan::class, ['record' => $this->growth->id])
        ->fillForm(['name' => 'Growth Plus', 'highlights' => "Everything in Starter\nYour own Paystack"])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->growth->fresh()->name)->toBe('Growth Plus')
        ->and($this->growth->fresh()->highlights)->toBe(['Everything in Starter', 'Your own Paystack'])
        ->and(AuditEntry::query()->where('event', 'plan.updated')->sole()->properties['attributes'])->toMatchArray(['name' => 'Growth Plus']);

    auth('staff')->logout();
    app()->forgetScopedInstances();
    $this->get('/')->assertOk()->assertSee('Growth Plus')->assertSee('Your own Paystack');
});

it('schedules new terms no sooner than 30 days away and cancels them before their date', function () {
    $this->actingAs($this->finance, 'staff');

    Livewire::test(EditPlan::class, ['record' => $this->growth->id])
        ->mountAction('schedule')
        ->assertActionDataSet(['effective_from' => '2027-01-01', 'base' => '35000.00', 'included_units' => 50])
        ->setActionData(['base' => '40000', 'effective_from' => '2026-11-20', 'reason' => 'Costs'])
        ->callMountedAction()
        ->assertHasActionErrors(['effective_from']);
    expect(PlanVersion::query()->where('plan_id', $this->growth->id)->count())->toBe(1);

    Livewire::test(EditPlan::class, ['record' => $this->growth->id])
        ->callAction('schedule', data: ['base' => '40,000', 'effective_from' => '2027-01-01', 'reason' => 'Costs'])
        ->assertNotified('Version 2 starts on 1 January 2027. Customers are being told now.');

    $version = PlanVersion::query()->where('plan_id', $this->growth->id)->where('version', 2)->sole();
    expect($version->base_price_kobo)->toBe(4_000_000)->and($version->created_by_staff_id)->toBe($this->finance->id);

    $this->get($this->console.'/plans/'.$this->growth->id.'/edit')->assertOk()
        ->assertSee('Version 2 from 1 January 2027: Base price: ₦35,000 → ₦40,000 a month.');

    Livewire::test(VersionsRelationManager::class, ['ownerRecord' => $this->growth, 'pageClass' => EditPlan::class])
        ->assertCanSeeTableRecords([$version])
        ->assertTableActionVisible('cancel', $version)
        ->callTableAction('cancel', $version, data: ['reason' => 'Board said no'])
        ->assertNotified('Version 2 cancelled');

    expect($version->fresh()->cancelled_at)->not->toBeNull();
});

it('creates a new plan, not offered until switched on', function () {
    $this->actingAs($this->finance, 'staff');

    Livewire::test(ListPlans::class)
        ->callAction('newPlan', data: ['key' => 'estates', 'name' => 'Estates', 'base' => '150000', 'included_units' => 500, 'unit_price' => '300', 'reason' => 'For estate managers'])
        ->assertNotified('Created Estates. It is not offered to new signups until you switch that on.');

    $plan = SubscriptionPlan::query()->where('key', 'estates')->sole();
    expect($plan->is_active)->toBeFalse()
        ->and(planNamed('estates')->base->kobo)->toBe(15_000_000)
        ->and(collect(catalogue()->plans())->pluck('key')->all())->not->toContain('estates');
});
