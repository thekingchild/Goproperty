<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Enums\TransactionTier;
use App\Models\Organization;
use App\Models\User;
use App\Organizations\OrganizationProfile;
use App\Tenancy\OrganizationContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/../../Support/organization-settings.php';

/*
 * P01-05, Settings → Profile: name, web address (old addresses keep
 * redirecting), address, state, LGA, CAC number; the registered name and
 * CAC number locked from Tier 2; who may change it.
 */

beforeEach(function () {
    seedOrganizationRoles();

    $this->acme = Organization::factory()->create(['name' => 'Acme Homes Ltd', 'slug' => 'acme-homes', 'state' => 'Lagos']);
    $this->owner = joinAs(User::factory()->create(), $this->acme);
});

afterEach(fn () => app(OrganizationContext::class)->clear());

/** @param  array<string, string>  $changes */
function profileInput(array $changes = []): array
{
    return $changes + ['name' => 'Acme Homes Ltd', 'slug' => 'acme-homes', 'address' => '1 Marina, Lagos Island', 'state' => 'Lagos', 'lga' => 'Lagos Island', 'cacNumber' => 'RC 7654321'];
}

/** @return array<string, list<string>> */
function profileErrors(Organization $organization, User $by, array $changes): array
{
    try {
        app(OrganizationProfile::class)->update($by, $organization, profileInput($changes));
    } catch (ValidationException $invalid) {
        return $invalid->errors();
    }

    return [];
}

it('saves the profile from the page, encrypting the CAC number and auditing the change', function () {
    $snapshot = settingsPage($this->owner, 'profile');

    $response = LivewireHttp::update($this, $snapshot, ['name' => 'Acme Homes Limited', 'address' => '1 Marina, Lagos Island', 'state' => 'Ogun', 'lga' => 'Abeokuta South', 'cacNumber' => 'rc 7654321'], [['save']])->assertOk();
    expect(LivewireHttp::html($response))->toContain('Profile saved.');

    $fresh = $this->acme->fresh();
    expect($fresh->name)->toBe('Acme Homes Limited')
        ->and($fresh->state)->toBe('Ogun')
        ->and($fresh->lga)->toBe('Abeokuta South')
        ->and($fresh->cac_number)->toBe('RC 7654321')
        ->and(DB::table('organizations')->where('id', $this->acme->id)->value('cac_number'))->not->toContain('7654321')
        ->and(AuditEntry::query()->where('event', 'organization.cac_number_changed')->sole()->causer_id)->toBe($this->owner->id)
        ->and(DB::table('activity_log')->where('attribute_changes', 'like', '%7654321%')->exists())->toBeFalse()
        ->and(data_get(AuditEntry::query()->where('log_name', 'organization')->where('event', 'updated')->latest('id')->firstOrFail()->attribute_changes, 'attributes.name'))->toBe('Acme Homes Limited');
});

it('moves the workspace to a new web address and keeps the old one redirecting', function () {
    $snapshot = settingsPage($this->owner, 'profile');

    $response = LivewireHttp::update($this, $snapshot, ['slug' => 'acme-realty'], [['save']])->assertOk();
    expect(LivewireHttp::redirect($response))->toEndWith('/o/acme-realty/settings/profile');

    $fresh = $this->acme->fresh();
    expect($fresh->slug)->toBe('acme-realty')->and($fresh->previous_slugs)->toBe(['acme-homes']);

    $this->actingAs($this->owner)->get('/o/acme-realty/settings/profile')->assertOk()->assertSee('the old address redirects here');
    $this->actingAs($this->owner)->get('/o/acme-homes/settings/branding')->assertRedirect('/o/acme-realty/settings/branding');

    // Taking back its own old address is allowed.
    expect(profileErrors($fresh, $this->owner, ['slug' => 'acme-homes']))->toBe([])
        ->and($this->acme->fresh()->slug)->toBe('acme-homes')
        ->and($this->acme->fresh()->previous_slugs)->toBe(['acme-realty']);
});

it('refuses a web address that is taken, reserved or malformed', function (string $slug, string $message) {
    Organization::factory()->create(['slug' => 'taken-realty', 'previous_slugs' => ['old-taken']]);

    expect(profileErrors($this->acme, $this->owner, ['slug' => $slug])['slug'][0] ?? '')->toContain($message)
        ->and($this->acme->fresh()->slug)->toBe('acme-homes');
})->with([
    'in use' => ['taken-realty', 'taken'],
    'someone\'s old address' => ['old-taken', 'taken'],
    'reserved' => ['support', 'reserved'],
    'capitals and spaces' => ['Acme Homes', 'lower-case'],
]);

it('locks the registered name and CAC number from Tier 2', function () {
    $this->acme->forceFill(['transaction_tier' => TransactionTier::Two])->save();
    $this->acme->cac_number = 'RC 1111111';
    $this->acme->save();

    $errors = profileErrors($this->acme->fresh(), $this->owner, ['name' => 'Renamed Ltd', 'cacNumber' => 'RC 2222222']);

    expect($errors['name'][0] ?? '')->toContain('checked by compliance')
        ->and($errors['cacNumber'][0] ?? '')->toContain('checked by compliance')
        ->and(profileErrors($this->acme->fresh(), $this->owner, ['cacNumber' => 'RC 1111111', 'address' => 'New address']))->toBe([])
        ->and($this->acme->fresh()->address)->toBe('New address');
});

it('lets administrators change the profile, not agents, and 404s for strangers', function () {
    $admin = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Admin);
    expect(profileErrors($this->acme, $admin, ['address' => 'Admin address']))->toBe([]);

    $agent = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent);
    $this->actingAs($agent)->get('/o/acme-homes/settings/profile')->assertForbidden();
    expect(fn () => profileErrors($this->acme, $agent, ['address' => 'Agent address']))->toThrow(AuthorizationException::class);

    $this->actingAs(User::factory()->create())->get('/o/acme-homes/settings/profile')->assertNotFound();
    expect($this->acme->fresh()->address)->toBe('Admin address');
});

it('shows the profile read-only while the organization is suspended', function () {
    $snapshot = settingsPage($this->owner, 'profile');
    $this->acme->forceFill(['status' => OrganizationStatus::Suspended])->save();

    $this->actingAs($this->owner)->get('/o/acme-homes/settings/profile')->assertOk()->assertDontSee('Save profile');
    LivewireHttp::update($this, $snapshot, ['name' => 'Suspended Edit'], [['save']])->assertForbidden();

    expect($this->acme->fresh()->name)->toBe('Acme Homes Ltd');
});

it('links the settings pages from the sidebar and lights up the checklist items', function () {
    $this->actingAs($this->owner)->get('/o/acme-homes/settings/profile')
        ->assertOk()
        ->assertSee('/o/acme-homes/settings/collections')
        ->assertSee('/o/acme-homes/settings/branding')
        ->assertSee('/o/acme-homes/settings/members');

    $admin = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Admin);
    $this->actingAs($admin)->get('/o/acme-homes/settings/profile')
        ->assertOk()
        ->assertDontSee('/o/acme-homes/settings/collections');
});
