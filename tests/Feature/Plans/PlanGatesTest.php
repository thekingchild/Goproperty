<?php

declare(strict_types=1);

use App\Branding\BrandingSettings;
use App\Enums\InvitationStatus;
use App\Enums\OrganizationRole;
use App\Members\MemberChangeRefused;
use App\Members\MemberInvitations;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\OrganizationFeatureOverride;
use App\Models\User;
use App\Plans\Feature;
use App\Plans\Features;
use App\Plans\PlanGate;
use App\Plans\PlanLimitReached;
use App\Plans\PlanUsage;
use App\Sms\Credits\SmsCredits;
use App\Subscriptions\SubscriptionService;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Modules\WhatsApp\Connections\WhatsAppPlan;
use Tests\Fixtures\Http\PlanGateProbe;

require_once __DIR__.'/../../Support/members.php';
require_once __DIR__.'/../../Support/plans.php';

/*
 * P06-01 acceptance: "over-limit actions blocked by middleware"
 * (ARCHITECTURE.md § 6.10, BILLING_PAYMENTS.md § 1.2; docs/plans/
 * P06-subtasks.md defaults 6–9). PlanGate refuses, before the action runs,
 * a controller action (SetOrganizationContext and the `plan` middleware)
 * or a Livewire call (RefuseLivewireCallsOutsidePlan) that the plan does
 * not include or that would go over a limit; the services check again.
 */

beforeEach(function () {
    Notification::fake();
    seedOrganizationRoles();
    $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00', 'Africa/Lagos'));
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Acme Homes']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ngozi Owner', 'email' => 'ngozi@acme.ng']), $this->acme);
    joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent);
    startTrial($this->acme, 'starter');

    Route::middleware(['web', 'auth', 'organization'])->prefix('o/{organization}')->group(function () {
        Route::post('plan-probe/paystack', [PlanGateProbe::class, 'paystack']);
        Route::post('plan-probe/staff', [PlanGateProbe::class, 'addStaff']);
        Route::post('plan-probe/whatsapp', [PlanGateProbe::class, 'plain'])->middleware('plan:whatsapp');
        Route::post('plan-probe/plain', [PlanGateProbe::class, 'plain']);
    });
    Route::getRoutes()->refreshNameLookups();
});

afterEach(fn () => membersFreshRequest());

function staffInvitations(): int
{
    return Invitation::query()->withoutGlobalScopes()->where('kind', 'user')->count();
}

it('acceptance: refuses the fourth staff user on Starter before the invite runs, members and waiting invitations counted', function () {
    // Starter: 3 staff users. Two members, so one invitation fits.
    inviteMember($this->owner, 'third@acme.ng');
    expect(staffInvitations())->toBe(1);

    // The Livewire call is refused by the hook, before the component or the service runs.
    membersCall($this->owner, [['invite']], ['email' => 'fourth@acme.ng', 'roles' => ['agent']])
        ->assertForbidden()
        ->assertSee('The Starter plan includes 3 staff users and Acme Homes has 3 (members and invitations still waiting). Move to Growth for more, or remove one first.');
    expect(staffInvitations())->toBe(1);

    // A controller action marked #[CountsAgainstLimit] is refused by the middleware the same way.
    membersFreshRequest();
    $this->actingAs($this->owner)->postJson('/o/acme-homes/plan-probe/staff')->assertForbidden()
        ->assertJsonPath('message', 'The Starter plan includes 3 staff users and Acme Homes has 3 (members and invitations still waiting). Move to Growth for more, or remove one first.');

    // The service refuses too, under its lock.
    expect(fn () => inOrganization($this->acme, fn () => app(MemberInvitations::class)->invite($this->owner, 'fourth@acme.ng', [OrganizationRole::Agent])))
        ->toThrow(MemberChangeRefused::class, 'The Starter plan includes 3 staff users');

    // Revoking the waiting invitation makes room again.
    $invitation = Invitation::query()->withoutGlobalScopes()->sole();
    inOrganization($this->acme, fn () => app(MemberInvitations::class)->revoke($this->owner, $invitation));
    inviteMember($this->owner, 'fourth@acme.ng');
    membersFreshRequest();
    $this->actingAs($this->owner)->postJson('/o/acme-homes/plan-probe/staff')->assertForbidden();
});

it('acceptance: lets a bigger plan or an override through, and counts nothing on an unlimited plan', function () {
    inviteMember($this->owner, 'third@acme.ng');
    membersCall($this->owner, [['invite']], ['email' => 'fourth@acme.ng', 'roles' => ['agent']])->assertForbidden();

    // An override of 4 (granted outside the plan) makes room for one more.
    inOrganization($this->acme, function (): void {
        $override = new OrganizationFeatureOverride;
        $override->forceFill(['organization_id' => $this->acme->id, 'feature_key' => Feature::StaffUsers->value, 'enabled' => true, 'limit_value' => 4, 'reason' => 'Pilot customer'])->save();
    });
    inviteMember($this->owner, 'fourth@acme.ng');
    membersCall($this->owner, [['invite']], ['email' => 'fifth@acme.ng', 'roles' => ['agent']])->assertForbidden();

    // An expired override no longer counts.
    $this->travel(1)->days();
    inOrganization($this->acme, fn () => OrganizationFeatureOverride::query()->sole()->forceFill(['expires_at' => now()->subMinute()])->save());
    expect(app(Features::class)->limit(Feature::StaffUsers, $this->acme))->toBe(3);

    // Growth: 10. Scale: unlimited.
    inOrganization($this->acme, fn () => app(SubscriptionService::class)->changePlan($this->owner, planNamed('growth')));
    inviteMember($this->owner, 'fifth@acme.ng');
    inOrganization($this->acme, fn () => app(SubscriptionService::class)->changePlan($this->owner, planNamed('scale')));
    expect(app(Features::class)->limit(Feature::StaffUsers, $this->acme))->toBeNull();
    membersFreshRequest();
    $this->actingAs($this->owner)->postJson('/o/acme-homes/plan-probe/staff')->assertOk();
});

it('acceptance: refuses features the plan does not include, by route middleware and action attribute', function () {
    $this->actingAs($this->owner)->postJson('/o/acme-homes/plan-probe/paystack')->assertForbidden()
        ->assertJsonPath('message', 'Online payments through your own Paystack is on the Growth plan and above. Acme Homes is on Starter. An owner can change plan in Settings → Subscription.');
    membersFreshRequest();
    $this->actingAs($this->owner)->postJson('/o/acme-homes/plan-probe/whatsapp')->assertForbidden();
    membersFreshRequest();
    $this->actingAs($this->owner)->postJson('/o/acme-homes/plan-probe/plain')->assertOk();

    inOrganization($this->acme, fn () => app(SubscriptionService::class)->changePlan($this->owner, planNamed('growth')));

    foreach (['paystack', 'whatsapp'] as $probe) {
        membersFreshRequest();
        $this->actingAs($this->owner)->postJson("/o/acme-homes/plan-probe/{$probe}")->assertOk()->assertJson(['ran' => true]);
    }
});

it('refuses an invitation\'s acceptance when a smaller plan since leaves no room', function () {
    inOrganization($this->acme, fn () => app(SubscriptionService::class)->changePlan($this->owner, planNamed('growth')));
    $tokens = [inviteMember($this->owner, 'third@acme.ng'), inviteMember($this->owner, 'fourth@acme.ng')];

    // Staff move the organization back to Starter (a customer could not: it is over the limit).
    seedStaffRoles();
    inOrganization($this->acme, fn () => app(SubscriptionService::class)->changePlan(staffNamed('Funmi', ['finance-admin']), planNamed('starter')));

    acceptAsNewPerson($tokens[0], ['name' => 'Third Person'])->assertRedirect();
    acceptAsNewPerson($tokens[1], ['name' => 'Fourth Person'])->assertForbidden()->assertSee('The Starter plan includes 3 staff users');

    expect(inOrganization($this->acme, fn () => app(PlanUsage::class)->members()))->toBe(3)
        ->and(Invitation::query()->withoutGlobalScopes()->where('email', 'fourth@acme.ng')->value('status'))->toBe(InvitationStatus::Pending)
        ->and(User::query()->where('name', 'Fourth Person')->exists())->toBeFalse();
});

it('reads WhatsApp, the "Generated with" line and the SMS allowance from the plan, with overrides', function () {
    $features = app(Features::class);
    expect(app(WhatsAppPlan::class)->allows($this->acme))->toBeFalse()
        ->and(app(BrandingSettings::class)->mayHidePoweredBy($this->acme))->toBeFalse()
        ->and(inOrganization($this->acme, fn (): int => app(SmsCredits::class)->monthlyAllowance()))->toBe(100);

    inOrganization($this->acme, fn () => app(SubscriptionService::class)->changePlan($this->owner, planNamed('scale')));
    expect(app(WhatsAppPlan::class)->allows($this->acme))->toBeTrue()
        ->and(app(BrandingSettings::class)->mayHidePoweredBy($this->acme))->toBeTrue()
        ->and(inOrganization($this->acme, fn (): int => app(SmsCredits::class)->monthlyAllowance()))->toBe(2_000);

    // An override switches a feature off for one organization only.
    inOrganization($this->acme, function (): void {
        $override = new OrganizationFeatureOverride;
        $override->forceFill(['organization_id' => $this->acme->id, 'feature_key' => Feature::WhatsApp->value, 'enabled' => false, 'reason' => 'Abuse report'])->save();
    });
    expect(app(WhatsAppPlan::class)->allows($this->acme))->toBeFalse()
        ->and($features->enabled(Feature::RemovePoweredBy, $this->acme))->toBeTrue();
});

it('does not gate an organization that has chosen no plan, and uses the signup choice before a subscription exists', function () {
    $fresh = Organization::factory()->create(['slug' => 'fresh-realty']);
    $features = app(Features::class);
    expect($features->planFor($fresh))->toBeNull()
        ->and($features->enabled(Feature::OwnPaystack, $fresh))->toBeTrue()
        ->and($features->limit(Feature::StaffUsers, $fresh))->toBeNull();

    $fresh->forceFill(['signup_plan_key' => 'starter'])->save();
    expect($features->planFor($fresh)?->key)->toBe('starter')
        ->and($features->enabled(Feature::OwnPaystack, $fresh))->toBeFalse();

    // Outside an organization the gate refuses nothing.
    app(OrganizationContext::class)->clear();
    app(PlanGate::class)->ensureCallAllowed(PlanGateProbe::class, 'paystack');
    expect(fn () => app(PlanGate::class)->ensureFeature(Feature::OwnPaystack, $fresh))->toThrow(PlanLimitReached::class);
});
