<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\User;
use App\Platform\Impersonation\ImpersonationMode;
use App\Settings\SettingsStore;
use App\Sms\Credits\SmsCredits;
use App\Sms\SmsOutbox;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/../Exports/helpers.php';
require_once __DIR__.'/../../../Modules/SaasBilling/tests/Feature/sms-credit-helpers.php';
require_once __DIR__.'/../Sms/fake-sms.php';

/*
 * P05-03: Settings → SMS credits (/o/{slug}/settings/sms-credits). Owners
 * and administrators see the balance, this month's allowance and what is
 * left of it, purchased credits and the ledger (25 a page, exportable);
 * owners buy bundles; never GoProperty staff while impersonating.
 */

beforeEach(function () {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-30 10:00', 'Africa/Lagos'));
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd', 'signup_plan_key' => 'growth']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ada Owner']), $this->acme);
    inOrganization($this->acme, function (): void {
        app(SmsCredits::class)->refreshAllowance();
        app(SmsCredits::class)->purchase(1_000, 12, 'Invoice PR-2026-000012');
    });
});

afterEach(fn () => exportsFreshRequest());

it('shows the balance, this month\'s allowance, the purchased credits, the bundles and the ledger', function () {
    platformPaystack();

    exportPage($this->owner, '/settings/sms-credits')->assertOk()
        ->assertSeeInOrder(['SMS credits', 'Balance', '1,500', 'Allowance for November 2026', '500 of 500 left', 'Resets on 1 December 2026', 'Purchased', '1,000', 'Never expire'])
        ->assertSee('data-test="buy-500"', false)
        ->assertSee('₦3,225, VAT included')
        ->assertSeeInOrder(['Credit history', 'Bought', 'Invoice PR-2026-000012', '+1,000', 'Balance 1,500', 'Monthly allowance', 'Allowance for November 2026', '+500']);

    // The tab is in the settings navigation.
    exportPage($this->owner, '/settings/profile')->assertOk()->assertSee('/o/acme-homes/settings/sms-credits');
});

it('pages the ledger 25 at a time and exports all of it', function () {
    scriptedSms();

    foreach (range(1, 30) as $n) {
        inOrganization($this->acme, fn () => app(SmsOutbox::class)->queue('reminder.rent_due', '+23480311'.str_pad((string) $n, 5, '0', STR_PAD_LEFT), 'Adeyemi Realty: rent due.', 'reminder:'.$n));
    }

    $first = exportPage($this->owner, '/settings/sms-credits')->assertOk();
    expect(substr_count((string) $first->getContent(), 'data-test="entry-'))->toBe(25);
    $first->assertSee('Rent due reminder to +234 ••• 0030')->assertDontSee('+2348031100030');

    $rows = exportRows($this->owner, '/settings/sms-credits', 'sms_credit_entries');
    expect($rows[0])->toBe(['Date', 'Entry', 'Credits', 'Pool', 'Balance after', 'Details'])
        ->and($rows)->toHaveCount(1 + 32)
        ->and($rows[1])->toBe(['2026-11-30 10:00', 'SMS sent', '-1', 'Monthly allowance', '1470', 'Rent due reminder to +234 ••• 0030'])
        ->and($rows[32])->toBe(['2026-11-30 10:00', 'Monthly allowance', '500', 'Monthly allowance', '500', 'Allowance for November 2026']);
});

it('warns when the credits run low and when they are used up', function () {
    $low = Organization::factory()->create(['slug' => 'low-realty', 'signup_plan_key' => 'starter']);
    $lowOwner = joinAs(User::factory()->create(), $low);
    app(SettingsStore::class)->set('sms_credits.monthly_allowance', 0, $low);
    inOrganization($low, fn () => app(SmsCredits::class)->refreshAllowance());

    exportPage($lowOwner, '/settings/sms-credits', 'low-realty')->assertOk()
        ->assertSee('No SMS credits left: SMS reminders have stopped.')
        ->assertSee('Email and in-app reminders carry on.');
    exportPage($this->owner, '/settings/sms-credits')->assertOk()->assertDontSee('SMS reminders have stopped');
});

it('lets administrators see it and agents, tenants and other organizations\' members not', function () {
    $admin = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Admin);
    $agent = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent);
    $outsider = joinAs(User::factory()->create(), Organization::factory()->create(['slug' => 'other-realty']));

    exportPage($admin, '/settings/sms-credits')->assertOk()->assertSee('1,500');
    exportPage($agent, '/settings/sms-credits')->assertForbidden();
    exportPage($outsider, '/settings/sms-credits')->assertNotFound();
    exportPage($agent, '/settings/profile')->assertDontSee('/o/acme-homes/settings/sms-credits');
});

it('stays readable while the organization is suspended, but buying waits until it is reactivated', function () {
    $http = platformPaystack();
    $this->acme->update(['status' => OrganizationStatus::Suspended]);

    exportPage($this->owner, '/settings/sms-credits')->assertOk()->assertSee('1,500');
    exportsFreshRequest();
    buyCredits($this->owner, 500)->assertForbidden();

    $http->assertNothingSent();
});

it('shows GoProperty staff the credits in a support session but never lets them buy', function (ImpersonationMode $mode) {
    $http = platformPaystack();
    seedStaffRoles();
    $staff = staffMember(['name' => 'Ada Okafor', 'email' => 'ada@goproperty.ng']);
    enterSupport(startSupport($staff, $this->owner, $this->acme, $mode))->assertRedirect('/o/acme-homes');

    $page = asSupport()->get('/o/acme-homes/settings/sms-credits')->assertOk()->assertSee('1,500')->assertDontSee('data-test="buy-500"', false);
    asSupport();
    LivewireHttp::update(test(), LivewireHttp::snapshot($page), [], [['buy', [500]]])->assertForbidden();

    $http->assertNothingSent();
})->with([
    'read-only' => ImpersonationMode::ReadOnly,
    'write' => ImpersonationMode::Write,
]);
