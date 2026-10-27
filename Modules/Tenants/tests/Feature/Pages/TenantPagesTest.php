<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Modules\Tenants\Enums\KycStatus;
use Modules\Tenants\Enums\TenantIdType;
use Modules\Tenants\Livewire\TenantIndex;
use Modules\Tenants\Models\TenantProfile;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/helpers.php';

/*
 * P03-01a: the tenant pages (/o/{slug}/tenants): add, edit, archive and
 * restore a tenant, the tenant's record page, the list with its filters
 * and search; the phone number unique in the organization with "restore
 * instead" for an archived match; the ID number encrypted and never sent
 * back whole.
 */

beforeEach(function () {
    seedOrganizationRoles();
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Acme Homes']);
    $this->owner = joinAs(User::factory()->create(), $this->acme);
});

afterEach(fn () => tenantPagesFreshRequest());

it('adds a tenant from the page, normalising the details, audited, and contacts nobody', function () {
    Notification::fake();
    Mail::fake();

    $response = LivewireHttp::update($this, tenantsSnapshot($this->owner, '/create'), tenantForm(), [['save']])->assertOk();

    $tenant = tenantsOf($this->acme)->sole();
    expect(LivewireHttp::redirect($response))->toBe("http://localhost/o/acme-homes/tenants/{$tenant->id}")
        ->and($tenant->organization_id)->toBe($this->acme->id)
        ->and($tenant->fullName())->toBe('Adaeze Okafor')
        ->and($tenant->phone)->toBe('+2348031234567')
        ->and($tenant->email)->toBe('adaeze@example.ng')
        ->and($tenant->emergency_contact)->toEqual(['name' => 'Chidi Okafor', 'phone' => '+2348059876543', 'relationship' => 'Brother'])
        ->and($tenant->id_type)->toBe(TenantIdType::Nin)
        ->and($tenant->id_value)->toBe('12345678901')
        ->and($tenant->kyc_status)->toBe(KycStatus::Unverified)
        ->and($tenant->user_id)->toBeNull()
        ->and($tenant->import_batch_id)->toBeNull();

    $entry = AuditEntry::query()->where('log_name', 'tenants')->sole();
    expect($entry->event)->toBe('tenant.created')
        ->and($entry->subject_id)->toBe($tenant->id)
        ->and($entry->causer_id)->toBe($this->owner->id)
        ->and(json_encode($entry->properties))->not->toContain('12345678901');

    Notification::assertNothingSent();
    Mail::assertNothingSent();

    tenantsPage($this->owner)->assertOk()->assertSee('Adaeze Okafor')->assertSee('+2348031234567');
    tenantsPage($this->owner, "/{$tenant->id}")->assertOk()
        ->assertSee('Adaeze Okafor')->assertSee('Chidi Okafor')->assertSee('+2348059876543')->assertSee('Brother')
        ->assertSee('NIN ···8901')->assertSee('Not verified')->assertSee('Not invited yet')
        ->assertSee('data-test="tenant-leases-bills"', false);
});

it('refuses invalid details on the server, saving nothing', function (array $changes, string $field) {
    $response = LivewireHttp::update($this, tenantsSnapshot($this->owner, '/create'), tenantForm($changes), [['save']])->assertOk();

    expect(LivewireHttp::errors($response))->toHaveKey($field)
        ->and(tenantsOf($this->acme))->toHaveCount(0);
})->with([
    'no first name' => [['firstName' => ''], 'firstName'],
    'no last name' => [['lastName' => '  '], 'lastName'],
    'no phone' => [['phone' => ''], 'phone'],
    'bad phone' => [['phone' => '12345'], 'phone'],
    'bad email' => [['email' => 'not-an-email'], 'email'],
    'bad next of kin phone' => [['kinPhone' => '0805'], 'kinPhone'],
    'short NIN' => [['idValue' => '1234567'], 'idValue'],
    'missing ID number' => [['idValue' => ''], 'idValue'],
    'bad passport number' => [['idType' => 'passport', 'idValue' => '12345678901'], 'idValue'],
    'unknown ID type' => [['idType' => 'bvn'], 'idType'],
    'unknown KYC status' => [['kycStatus' => 'approved'], 'kycStatus'],
    'verified without an ID' => [['idType' => 'none', 'idValue' => '', 'kycStatus' => 'verified'], 'kycStatus'],
    'markup in a name' => [['lastName' => '<b>Okafor</b>'], 'lastName'],
    'occupation too long' => [['occupation' => str_repeat('a', 121)], 'occupation'],
]);

it('stores the ID number encrypted at rest and never sends it to the browser whole', function () {
    $tenant = tenantIn($this->acme, ['first_name' => 'Tunde', 'last_name' => 'Bakare', 'id_type' => TenantIdType::Nin, 'id_value' => '98765432109']);
    $raw = (string) DB::table('tenant_profiles')->where('id', $tenant->id)->value('id_value');

    expect($raw)->not->toContain('98765432109')
        ->and(Crypt::decryptString($raw))->toBe('98765432109');

    // The record page, the edit page and their Livewire snapshots carry only the masked number.
    foreach (["/{$tenant->id}", "/{$tenant->id}/edit", ''] as $path) {
        $page = tenantsPage($this->owner, $path)->assertOk()->assertDontSee('98765432109');
        expect(LivewireHttp::snapshot($page))->not->toContain('98765432109')->not->toContain($raw);
    }

    tenantsPage($this->owner, "/{$tenant->id}")->assertSee('NIN ···2109');
    tenantsPage($this->owner, "/{$tenant->id}/edit")->assertSee('NIN ···2109');
});

it('keeps the ID number on file when the field is left empty, and replaces or removes it on purpose', function () {
    $tenant = tenantIn($this->acme, ['first_name' => 'Tunde', 'last_name' => 'Bakare', 'id_type' => TenantIdType::Nin, 'id_value' => '98765432109']);
    $form = tenantsSnapshot($this->owner, "/{$tenant->id}/edit");

    $response = LivewireHttp::update($this, $form, ['phone' => '0812 000 1111'], [['save']])->assertOk();
    expect(LivewireHttp::redirect($response))->toBe("http://localhost/o/acme-homes/tenants/{$tenant->id}")
        ->and($tenant->fresh()->id_value)->toBe('98765432109')
        ->and($tenant->fresh()->phone)->toBe('+2348120001111')
        ->and(AuditEntry::query()->where('event', 'tenant.updated')->sole()->properties->get('changed'))->toBe(['phone']);

    // A new number replaces it; the audit names the field, never the number.
    LivewireHttp::update($this, tenantsSnapshot($this->owner, "/{$tenant->id}/edit"), ['idValue' => '11122233344'], [['save']])->assertOk();
    expect($tenant->fresh()->id_value)->toBe('11122233344')
        ->and(AuditEntry::query()->where('event', 'tenant.updated')->latest('id')->first()->properties->get('changed'))->toBe(['id_value'])
        ->and(json_encode(AuditEntry::query()->where('log_name', 'tenants')->pluck('properties')))->not->toContain('11122233344')->not->toContain('98765432109');

    // Another document needs its number; "None" removes it.
    $refused = LivewireHttp::update($this, tenantsSnapshot($this->owner, "/{$tenant->id}/edit"), ['idType' => 'passport'], [['save']])->assertOk();
    expect(LivewireHttp::errors($refused))->toHaveKey('idValue');

    LivewireHttp::update($this, tenantsSnapshot($this->owner, "/{$tenant->id}/edit"), ['idType' => 'none'], [['save']])->assertOk();
    expect($tenant->fresh()->id_type)->toBe(TenantIdType::None)
        ->and($tenant->fresh()->id_value)->toBeNull();

    // Saving without a change writes no audit entry.
    $before = AuditEntry::query()->where('log_name', 'tenants')->count();
    LivewireHttp::update($this, tenantsSnapshot($this->owner, "/{$tenant->id}/edit"), [], [['save']])->assertOk();
    expect(AuditEntry::query()->where('log_name', 'tenants')->count())->toBe($before);
});

it('records the KYC status, needs an ID for it, and unverifies a checked tenant whose ID changes', function () {
    $tenant = tenantIn($this->acme, ['first_name' => 'Tunde', 'last_name' => 'Bakare']);

    $refused = LivewireHttp::update($this, tenantsSnapshot($this->owner, "/{$tenant->id}/edit"), ['kycStatus' => 'pending'], [['save']])->assertOk();
    expect(LivewireHttp::errors($refused))->toHaveKey('kycStatus')
        ->and($tenant->fresh()->kyc_status)->toBe(KycStatus::Unverified);

    LivewireHttp::update($this, tenantsSnapshot($this->owner, "/{$tenant->id}/edit"), ['idType' => 'nin', 'idValue' => '98765432109', 'kycStatus' => 'verified'], [['save']])->assertOk();
    expect($tenant->fresh()->kyc_status)->toBe(KycStatus::Verified);
    $entry = AuditEntry::query()->where('event', 'tenant.updated')->latest('id')->first();
    expect($entry->properties->get('kyc_from'))->toBe('unverified')
        ->and($entry->properties->get('kyc_to'))->toBe('verified');
    tenantsPage($this->owner, "/{$tenant->id}")->assertSee('Verified');

    // Another ID number: the check was of another document.
    LivewireHttp::update($this, tenantsSnapshot($this->owner, "/{$tenant->id}/edit"), ['idValue' => '11122233344'], [['save']])->assertOk();
    expect($tenant->fresh()->kyc_status)->toBe(KycStatus::Unverified);

    // Unless the same save sets the status on purpose.
    LivewireHttp::update($this, tenantsSnapshot($this->owner, "/{$tenant->id}/edit"), ['idType' => 'passport', 'idValue' => 'a01234567', 'kycStatus' => 'verified'], [['save']])->assertOk();
    expect($tenant->fresh()->kyc_status)->toBe(KycStatus::Verified)
        ->and($tenant->fresh()->id_value)->toBe('A01234567');
});

it('refuses a phone number another tenant has, naming them', function () {
    tenantIn($this->acme, ['first_name' => 'Ngozi', 'last_name' => 'Eze', 'phone' => '+2348031234567']);
    $other = tenantIn($this->acme, ['first_name' => 'Tunde', 'last_name' => 'Bakare', 'phone' => '+2348120001111']);

    // Adding: the number written another way is the same number.
    $response = LivewireHttp::update($this, tenantsSnapshot($this->owner, '/create'), tenantForm(['phone' => '+234 803 123 4567']), [['save']])->assertOk();
    expect(LivewireHttp::errors($response)['phone'][0])->toBe('This phone number belongs to Ngozi Eze. Each tenant needs their own number.')
        ->and(LivewireHttp::html($response))->not->toContain('data-test="restore-instead"');

    // Editing another tenant to that number.
    $response = LivewireHttp::update($this, tenantsSnapshot($this->owner, "/{$other->id}/edit"), ['phone' => '08031234567'], [['save']])->assertOk();
    expect(LivewireHttp::errors($response)['phone'][0])->toBe('This phone number belongs to Ngozi Eze. Each tenant needs their own number.')
        ->and($other->fresh()->phone)->toBe('+2348120001111')
        ->and(tenantsOf($this->acme))->toHaveCount(2);
});

it('offers to restore an archived tenant with the same phone number instead of adding them again', function () {
    $archived = tenantIn($this->acme, ['first_name' => 'Ngozi', 'last_name' => 'Eze', 'phone' => '+2348031234567', 'email' => 'ngozi@eze.ng']);
    inOrganization($this->acme, fn () => $archived->delete());

    $response = LivewireHttp::update($this, tenantsSnapshot($this->owner, '/create'), tenantForm(['phone' => '0803 123 4567']), [['save']])->assertOk();

    expect(LivewireHttp::errors($response)['phone'][0])->toBe('Ngozi Eze has this phone number and was archived. Restore them instead of adding them again.')
        ->and(LivewireHttp::html($response))->toContain('data-test="restore-instead"')->toContain('Restore Ngozi Eze')
        ->and(tenantsOf($this->acme))->toHaveCount(1);

    // Restoring brings back the record on file (not the typed details), audited.
    $restored = LivewireHttp::update($this, LivewireHttp::next($response), [], [['restoreArchivedMatch']])->assertOk();

    expect(LivewireHttp::redirect($restored))->toBe("http://localhost/o/acme-homes/tenants/{$archived->id}")
        ->and($archived->fresh()->trashed())->toBeFalse()
        ->and($archived->fresh()->first_name)->toBe('Ngozi')
        ->and($archived->fresh()->email)->toBe('ngozi@eze.ng')
        ->and(tenantsOf($this->acme))->toHaveCount(1)
        ->and(AuditEntry::query()->where('log_name', 'tenants')->pluck('event')->all())->toBe(['tenant.restored']);

    // A form that never met an archived match has nothing to restore.
    LivewireHttp::update($this, tenantsSnapshot($this->owner, '/create'), [], [['restoreArchivedMatch']])->assertNotFound();

    // Editing a tenant to an archived tenant's number is refused, naming them.
    $other = tenantIn($this->acme, ['first_name' => 'Tunde', 'last_name' => 'Bakare']);
    $gone = tenantIn($this->acme, ['first_name' => 'Old', 'last_name' => 'Tenant', 'phone' => '+2348090000001']);
    inOrganization($this->acme, fn () => $gone->delete());
    $response = LivewireHttp::update($this, tenantsSnapshot($this->owner, "/{$other->id}/edit"), ['phone' => '0809 000 0001'], [['save']])->assertOk();
    expect(LivewireHttp::errors($response)['phone'][0])->toBe('This phone number belongs to Old Tenant, an archived tenant. Each tenant needs their own number.')
        ->and(LivewireHttp::html($response))->not->toContain('data-test="restore-instead"');
});

it('archives a tenant as a soft delete and restores them, from the list and the record page, audited', function () {
    $tenant = tenantIn($this->acme, ['first_name' => 'Tunde', 'last_name' => 'Bakare']);

    $response = LivewireHttp::update($this, tenantsSnapshot($this->owner), [], [['archive', [$tenant->id]]])->assertOk();
    expect(LivewireHttp::html($response))->toContain('Tunde Bakare was archived.');

    $row = DB::table('tenant_profiles')->where('id', $tenant->id)->first();
    expect($row)->not->toBeNull()->and($row->deleted_at)->not->toBeNull();

    // Gone from the active list; listed under Archived; the record opens read-only.
    tenantsPage($this->owner)->assertOk()->assertDontSee('Tunde Bakare');
    tenantsPage($this->owner, '?archived=1')->assertOk()->assertSee('Tunde Bakare')->assertSee('data-test="restore-'.$tenant->id.'"', false);
    tenantsPage($this->owner, "/{$tenant->id}")->assertOk()->assertSee('data-test="archived-banner"', false)
        ->assertSee('data-test="restore-tenant"', false)->assertDontSee('data-test="edit-tenant"', false)->assertDontSee('data-test="archive-tenant"', false);
    tenantsPage($this->owner, "/{$tenant->id}/edit")->assertNotFound();

    LivewireHttp::update($this, tenantsSnapshot($this->owner, '?archived=1'), [], [['restore', [$tenant->id]]])->assertOk();
    expect($tenant->fresh()->trashed())->toBeFalse();

    // And again from the record page.
    $page = tenantsSnapshot($this->owner, "/{$tenant->id}");
    $archived = LivewireHttp::update($this, $page, [], [['archive']])->assertOk();
    expect($tenant->fresh()->trashed())->toBeTrue()
        ->and(LivewireHttp::html($archived))->toContain('data-test="restore-tenant"');
    LivewireHttp::update($this, LivewireHttp::next($archived), [], [['restore']])->assertOk();

    expect($tenant->fresh()->trashed())->toBeFalse()
        ->and(DB::table('tenant_profiles')->count())->toBe(1)
        ->and(AuditEntry::query()->where('log_name', 'tenants')->orderBy('id')->pluck('event')->all())
        ->toBe(['tenant.archived', 'tenant.restored', 'tenant.archived', 'tenant.restored']);
});

it('never deletes a tenant row for good: the module archives (soft delete) and has no hard delete', function () {
    $tenant = tenantIn($this->acme);

    // Archiving through the writer leaves the row, with deleted_at set.
    LivewireHttp::update($this, tenantsSnapshot($this->owner, "/{$tenant->id}"), [], [['archive']])->assertOk();
    expect(DB::table('tenant_profiles')->where('id', $tenant->id)->value('deleted_at'))->not->toBeNull();

    // No code of the module removes a row for good (AGENT.md § 3 rule 7).
    $offending = [];

    foreach (File::allFiles(dirname(__DIR__, 3).'/app') as $file) {
        $source = (string) file_get_contents($file->getPathname());

        // The portal's "linked to my user" path (P03-01b, ARCHITECTURE.md § 4) reads tenant_profiles raw, and only reads.
        $reader = $file->getRelativePathname() === 'Portal/LinkedTenantProfiles.php'
            && preg_match('/->(delete|update|insert\w*|upsert|truncate|increment|decrement)\(/', $source) === 0;

        if (preg_match('/forceDelete|forceDestroy|->truncate\(/', $source) === 1
            || (! $reader && preg_match('/DB::table\([\'"]tenant_profiles/', $source) === 1)) {
            $offending[] = $file->getRelativePathname();
        }
    }

    expect($offending)->toBe([])
        ->and(in_array(SoftDeletes::class, class_uses_recursive(TenantProfile::class), true))->toBeTrue();
});

it('searches by name, phone and email, filters by KYC status and portal access, and pages on the server', function () {
    inOrganization($this->acme, function (): void {
        TenantProfile::factory()->count(TenantIndex::PER_PAGE + 5)->sequence(fn ($sequence) => [
            'first_name' => 'Tenant',
            'last_name' => sprintf('N%03d', $sequence->index),
            // Fixed contacts: random ones could match a search term below.
            'email' => sprintf('tenant%03d@example.test', $sequence->index),
            'phone' => sprintf('+2348120000%03d', $sequence->index),
        ])->create();
        TenantProfile::factory()->withNin()->create(['first_name' => 'Chiamaka', 'last_name' => 'Eze', 'email' => 'chiamaka@eze.ng', 'phone' => '+2348091112222', 'kyc_status' => KycStatus::Verified]);
        TenantProfile::factory()->create(['first_name' => 'Kunle', 'last_name' => 'Adeyemi', 'email' => 'kunle@adeyemi.test', 'phone' => '+2348130000001', 'user_id' => User::factory()->create(['name' => 'Kunle Adeyemi', 'email' => 'kunle.user@adeyemi.test'])->id]);
    });

    // Ordered by last name: Adeyemi, Eze, then N000…
    tenantsPage($this->owner)->assertOk()->assertSeeInOrder(['Kunle Adeyemi', 'Chiamaka Eze', 'Tenant N000'])->assertDontSee('Tenant N029');
    tenantsPage($this->owner, '?page=2')->assertOk()->assertSee('Tenant N029')->assertDontSee('Chiamaka Eze');

    foreach (['chiam', 'Chiamaka E', 'eze.ng', '0809 111', '+234 809 111'] as $term) {
        tenantsPage($this->owner, '?q='.urlencode($term))->assertOk()->assertSee('Chiamaka Eze')->assertDontSee('Kunle Adeyemi')->assertDontSee('Tenant N000');
    }

    tenantsPage($this->owner, '?kyc=verified')->assertOk()->assertSee('Chiamaka Eze')->assertDontSee('Kunle Adeyemi')->assertDontSee('Tenant N000');
    tenantsPage($this->owner, '?portal=yes')->assertOk()->assertSee('Kunle Adeyemi')->assertDontSee('Chiamaka Eze');
    tenantsPage($this->owner, '?portal=no&kyc=unverified&q=N02')->assertOk()->assertSee('Tenant N020')->assertDontSee('Kunle Adeyemi')->assertDontSee('Chiamaka Eze');
    // Unknown filter values are ignored, as on the export.
    tenantsPage($this->owner, '?kyc=approved&portal=maybe')->assertOk()->assertSee('Kunle Adeyemi')->assertSee('Chiamaka Eze');

    // The search box and filters update the list through Livewire (search debounced on the page).
    $snapshot = tenantsSnapshot($this->owner);
    $html = LivewireHttp::html(LivewireHttp::update($this, $snapshot, ['search' => 'Adeyemi'])->assertOk());
    expect($html)->toContain('Kunle Adeyemi')->not->toContain('Chiamaka Eze');
    $html = LivewireHttp::html(LivewireHttp::update($this, $snapshot, ['kyc' => 'verified'])->assertOk());
    expect($html)->toContain('Chiamaka Eze')->not->toContain('Kunle Adeyemi');

    tenantsPage($this->owner, '?q='.urlencode('100%_'))->assertOk()->assertSee('No tenant matches')->assertSee('data-test="clear-filters"', false);
    $cleared = LivewireHttp::update($this, tenantsSnapshot($this->owner, '?q=zzz&kyc=pending'), [], [['clearFilters']])->assertOk();
    expect(LivewireHttp::html($cleared))->toContain('Kunle Adeyemi');
});

it('shows a clear next step when there are no tenants yet', function () {
    tenantsPage($this->owner)->assertOk()->assertSee('No tenants yet')->assertSee('/o/acme-homes/tenants/create');
    tenantsPage($this->owner, '?archived=1')->assertOk()->assertSee('No archived tenants');
});
