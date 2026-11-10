<?php

declare(strict_types=1);

use App\Enums\OrganizationStatus;
use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use App\Platform\Analytics\MetricsSnapshot;
use App\Platform\Deliveries\Health\MessagingHealth;
use App\Sms\DeliveryStatus;
use App\Sms\SmsOutbox;
use App\Subscriptions\SubscriptionChangeRefused;
use App\Subscriptions\SubscriptionService;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoOrganizationSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\SaasBilling\Contracts\CheckoutRefused;
use Modules\SaasBilling\SmsCredits\SmsCreditPurchases;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/../Exports/helpers.php';
require_once __DIR__.'/../../Support/plans.php';
require_once __DIR__.'/../Sms/fake-sms.php';

/*
 * P09-05: GoProperty's demo organization for staff walkthroughs
 * (BILLING_PAYMENTS.md § 1.4). Made by `goproperty:demo-organization`;
 * `is_demo`, no subscription, so never billed; its SMS are never sent;
 * the console's figures leave it out.
 */

function demoOrganization(string $slug = 'acme-homes'): Organization
{
    $organization = Organization::factory()->create(['slug' => $slug, 'name' => 'Demo Homes Ltd', 'status' => OrganizationStatus::Active, 'signup_plan_key' => 'scale']);
    $organization->forceFill(['is_demo' => true])->save();

    return $organization;
}

it('creates the demo organization once: six months of made-up history, no subscription, no contact emails', function () {
    seedOrganizationRoles();
    expect(Artisan::call('goproperty:demo-organization', ['email' => 'Demo@GoProperty.ng', '--force' => true]))->toBe(0);

    $demo = Organization::query()->where('is_demo', true)->sole();
    $count = fn (string $table): int => DB::table($table)->where('organization_id', $demo->id)->count();

    expect($demo->only(['name', 'slug']))->toBe(['name' => DemoOrganizationSeeder::NAME, 'slug' => DemoOrganizationSeeder::SLUG])
        ->and($demo->status)->toBe(OrganizationStatus::Active)
        ->and($demo->signup_plan_key)->not->toBeNull()
        ->and($count('subscriptions'))->toBe(0)
        ->and($count('platform_invoices'))->toBe(0)
        ->and([$count('properties'), $count('units'), $count('tenant_profiles'), $count('leases')])->toBe([3, 20, 18, 18])
        ->and(DB::table('tenant_profiles')->where('organization_id', $demo->id)->whereNotNull('email')->count())->toBe(0)
        ->and($count('bills'))->toBe(18 * DemoOrganizationSeeder::MONTHS)
        ->and(DB::table('bills')->where('organization_id', $demo->id)->where('status', 'overdue')->count())->toBe(4)
        ->and($count('payments'))->toBe(18 * DemoOrganizationSeeder::MONTHS - 4)
        ->and(DB::table('tickets')->where('organization_id', $demo->id)->pluck('status')->sort()->values()->all())->toBe(['in_progress', 'resolved', 'submitted'])
        ->and($count('group_posts'))->toBe(3)
        ->and(DB::table('notification_deliveries')->where('organization_id', $demo->id)->where('channel', 'sms')->whereNot('status', 'failed')->count())->toBe(0)
        ->and(DB::table('organization_user')->where('organization_id', $demo->id)->count())->toBe(1);

    $owner = User::query()->where('email', 'demo@goproperty.ng')->sole();
    expect($owner->email_verified_at)->not->toBeNull()
        ->and($owner->organizations()->pluck('slug')->all())->toBe([DemoOrganizationSeeder::SLUG])
        ->and(CarbonImmutable::now()->isToday())->toBeTrue(); // the clock is back

    // Once only: a second run changes nothing.
    Artisan::call('goproperty:demo-organization', ['email' => 'other@goproperty.ng', '--force' => true]);
    expect(Artisan::output())->toContain('already exists')
        ->and(Organization::query()->where('is_demo', true)->count())->toBe(1)
        ->and(User::query()->where('email', 'other@goproperty.ng')->exists())->toBeFalse();
});

it('never takes over an existing account, and asks first without --force', function () {
    User::factory()->create(['email' => 'ada@goproperty.ng']);

    expect(Artisan::call('goproperty:demo-organization', ['email' => 'ada@goproperty.ng', '--force' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('already has an account')
        ->and(Artisan::call('goproperty:demo-organization', ['email' => 'demo@goproperty.ng', '--no-interaction' => true]))->toBe(1)
        ->and(Organization::query()->where('is_demo', true)->exists())->toBeFalse();
});

it('never starts a subscription for the demo organization, so no billing job selects it', function () {
    $demo = demoOrganization();

    expect(fn () => inOrganization($demo, fn () => app(SubscriptionService::class)->start(null, planNamed('growth'), CarbonImmutable::now()->addDays(14))))
        ->toThrow(SubscriptionChangeRefused::class, 'demo organization');
    expect(inOrganization($demo, fn (): int => Subscription::query()->count()))->toBe(0);
});

it('never sends its SMS: the delivery fails with the reason, GoText is never called, no credit is taken', function () {
    $sms = scriptedSms();
    $demo = demoOrganization();

    inOrganization($demo, fn () => app(SmsOutbox::class)->queue('reminder.rent_due', '+2347000000001', 'Demo Homes: rent is due tomorrow.', 'demo:1'));

    $delivery = inOrganization($demo, fn (): NotificationDelivery => NotificationDelivery::query()->sole());
    expect($sms->calls)->toBe(0)
        ->and($delivery->status)->toBe(DeliveryStatus::Failed)
        ->and($delivery->error)->toBe('Not sent: a demo organization never sends SMS.')
        ->and(DB::table('sms_credit_entries')->where('organization_id', $demo->id)->where('credits', '<', 0)->count())->toBe(0);

    // Not GoText's failure: the provider's health leaves the demo organization out.
    expect(collect(app(MessagingHealth::class)->providers(CarbonImmutable::now()))->map(fn ($provider): array => [$provider->attempted, $provider->problems])->all())
        ->each->toBe([0, 0]);
});

it('does not sell the demo organization SMS credits', function () {
    $demo = demoOrganization();
    $owner = joinAs(User::factory()->create(), $demo);

    expect(fn () => inOrganization($demo, fn () => app(SmsCreditPurchases::class)->start($owner, 100, 'https://example.test/back')))
        ->toThrow(CheckoutRefused::class, 'demo organization');
});

it('says on Settings → Subscription that it is a demo, offers no plan moves and refuses one', function () {
    Notification::fake();
    $demo = demoOrganization();
    $owner = joinAs(User::factory()->create(), $demo);

    $page = exportPage($owner, '/settings/subscription')->assertOk()
        ->assertSee('data-test="demo-notice"', false)
        ->assertSee('It is never billed and sends no SMS')
        ->assertDontSee('data-test="move-starter"', false);
    exportsFreshRequest();

    LivewireHttp::update($this->actingAs($owner), LivewireHttp::snapshot($page), [], [['changePlan', ['growth']]])
        ->assertOk()->assertSee('its plan does not change here');
    expect(inOrganization($demo, fn (): int => Subscription::query()->count()))->toBe(0);

    exportsFreshRequest();
    exportPage($owner, '')->assertOk()->assertDontSee('Free trial:');
});

it('leaves the demo organization out of the console\'s analytics', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00', 'Africa/Lagos'));
    Organization::factory()->create(['state' => 'Lagos']);
    $demo = demoOrganization('demo-homes');
    $demo->forceFill(['state' => 'Lagos'])->save();

    $this->travelTo(CarbonImmutable::parse('2026-11-11 01:00', 'Africa/Lagos'));
    app(MetricsSnapshot::class)->take(CarbonImmutable::parse('2026-11-10', 'Africa/Lagos'), withStocks: true);
    $row = fn (string $metric): ?int => ($value = DB::table('daily_platform_metrics')->where('date', '2026-11-10')->where('metric', $metric)->where('dimension', '')->value('value')) === null ? null : (int) $value;

    expect($row('signups'))->toBe(1)
        ->and($row('customers_by_status'))->toBe(1)
        ->and($row('organizations_by_state'))->toBe(1)
        ->and($row('organizations_by_tier'))->toBe(1);
});
