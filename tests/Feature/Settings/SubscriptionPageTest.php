<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use App\Platform\Impersonation\ImpersonationMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/../Exports/helpers.php';
require_once __DIR__.'/../../Support/plans.php';
require_once __DIR__.'/../../Support/impersonation.php';

/*
 * P06-01b: Settings → Subscription (/o/{slug}/settings/subscription) and
 * the trial banner. Owners and administrators see the plan, its state,
 * today's bill, what it includes and the other plans; owners move plan at
 * once; never GoProperty staff while impersonating.
 */

beforeEach(function () {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00', 'Africa/Lagos'));
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ada Owner']), $this->acme);
    $this->admin = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Admin);
    $this->agent = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent);
    startTrial($this->acme, 'growth', 14);
});

afterEach(fn () => exportsFreshRequest());

it('shows the plan, the trial, the bill, what is included and the other plans', function () {
    exportPage($this->owner, '/settings/subscription')->assertOk()
        ->assertSeeInOrder(['Your plan', 'Growth', 'Free trial', 'Monthly, at today', '₦35,000', 'Free trial', '14 days left', 'Ends 16 November 2026'])
        ->assertSeeInOrder(['What your plan includes', 'Agency\'s own Paystack', 'Included', 'Approval workflows', 'Not included', 'Staff users', '10', '3 in use', 'SMS credits included each month', '500'])
        ->assertSee('data-test="move-starter"', false)
        ->assertSee('data-test="move-scale"', false)
        ->assertDontSee('data-test="move-enterprise"', false);

    // Administrators see it without the buttons; agents not at all.
    exportPage($this->admin, '/settings/subscription')->assertOk()->assertSee('Growth')->assertDontSee('data-test="move-starter"', false);
    exportPage($this->agent, '/settings/subscription')->assertForbidden();

    // The trial banner is on every workspace page for owners and administrators.
    exportPage($this->owner, '')->assertOk()->assertSee('Free trial: 14 days left')->assertSee('Your Growth plan starts on 16 November 2026.');
    exportPage($this->agent, '')->assertOk()->assertDontSee('Free trial:');
});

it('moves the plan at once for an owner, and refuses one whose limits the organization is over', function () {
    $page = exportPage($this->owner, '/settings/subscription')->assertOk();
    exportsFreshRequest();
    LivewireHttp::update($this->actingAs($this->owner), LivewireHttp::snapshot($page), [], [['changePlan', ['scale']]])
        ->assertOk()->assertSee('You are now on Scale.');
    expect(inOrganization($this->acme, fn (): string => Subscription::query()->sole()->plan->key))->toBe('scale');

    joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent);
    $page = exportPage($this->owner, '/settings/subscription')->assertOk();
    exportsFreshRequest();
    LivewireHttp::update($this->actingAs($this->owner), LivewireHttp::snapshot($page), [], [['changePlan', ['starter']]])
        ->assertOk()->assertSee('Starter includes 3 staff users and Adeyemi Realty Ltd has 4');
    expect(inOrganization($this->acme, fn (): string => Subscription::query()->sole()->plan->key))->toBe('scale');
});

it('never lets impersonating staff or an administrator move the plan', function () {
    $page = exportPage($this->admin, '/settings/subscription')->assertOk();
    exportsFreshRequest();
    LivewireHttp::update($this->actingAs($this->admin), LivewireHttp::snapshot($page), [], [['changePlan', ['scale']]])->assertForbidden();

    seedStaffRoles();
    exportsFreshRequest();
    enterSupport(startSupport(staffMember(['name' => 'Sola Support', 'email' => 'sola@goproperty.ng']), $this->owner, $this->acme, ImpersonationMode::ReadOnly))->assertRedirect('/o/acme-homes');
    $page = asSupport()->get('/o/acme-homes/settings/subscription')->assertOk()->assertDontSee('data-test="move-scale"', false);
    asSupport();
    LivewireHttp::update(test(), LivewireHttp::snapshot($page), [], [['changePlan', ['scale']]])->assertForbidden();

    expect(inOrganization($this->acme, fn (): string => Subscription::query()->sole()->plan->key))->toBe('growth');
});
