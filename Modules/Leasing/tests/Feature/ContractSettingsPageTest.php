<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Documents\DocumentRenderer;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\User;
use App\Settings\SettingsStore;
use Illuminate\Testing\TestResponse;
use Modules\Leasing\Documents\ContractClauses;
use Modules\Leasing\Documents\LeaseContracts;
use Modules\Leasing\Models\Lease;
use Modules\Leasing\Models\LeaseContract;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/helpers.php';

/*
 * P03-03b: Settings → Lease contract, the agency's clause template
 * (BRANDING_DOCUMENTS.md § 4 "clauses (agency-editable template)"):
 * owners and administrators edit it, agents never see it; it is plain text
 * with whitelisted placeholders (AGENT.md § 3 rule 10), audited, and
 * printed on contracts issued after the change only.
 */

beforeEach(function () {
    seedOrganizationRoles();
    $this->travelTo('2026-12-20 10:00:00');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $this->owner = joinAs(User::factory()->create(), $this->acme);
});

afterEach(fn () => leasesFreshRequest());

/** The clause settings page, as the user. */
function clauseSettingsPage(User $user): TestResponse
{
    leasesFreshRequest();

    return test()->actingAs($user)->get('/o/acme-homes/settings/documents/lease-contract');
}

/** A Livewire update on the clause settings page, as a fresh request by the same user. */
function clauseSettingsUpdate(User $user, array $updates = [], array $calls = []): TestResponse
{
    $snapshot = LivewireHttp::snapshot(clauseSettingsPage($user)->assertOk());
    leasesFreshRequest();
    test()->actingAs($user);

    return LivewireHttp::update(test(), $snapshot, $updates, $calls);
}

/** The organization's stored clause template. */
function storedClauses(Organization $organization): mixed
{
    return inOrganization($organization, fn () => app(SettingsStore::class)->get(ContractClauses::SETTING));
}

it('shows owners and administrators the clauses with the placeholders and a preview, under a Lease contract tab', function (OrganizationRole $role) {
    $member = joinAs(User::factory()->create(), $this->acme, $role);

    clauseSettingsPage($member)->assertOk()
        ->assertSee('Lease contract clauses')
        ->assertSee('data-test="settings-nav"', false)
        ->assertSee('href="http://localhost/o/acme-homes/settings/documents/lease-contract"', false)
        ->assertSee('aria-current="page"', false)
        ->assertSee('{tenant_name}')
        ->assertSee('{contract_number}')
        ->assertSee('The Landlord, {landlord}, through {agency_name}', false)
        ->assertSee('Palm Court Apartments')
        ->assertSee('Save clauses');

    // The tab shows on the other settings pages too, and the sidebar's Settings item is lit on this one.
    leasesFreshRequest();
    $this->actingAs($member)->get('/o/acme-homes/settings/branding')->assertOk()->assertSeeInOrder(['Branding', 'Lease contract', 'Members']);
})->with([
    'org-owner' => [OrganizationRole::Owner],
    'org-admin' => [OrganizationRole::Admin],
]);

it('keeps agents and anyone outside the organization out', function () {
    $agent = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent);

    clauseSettingsPage($agent)->assertForbidden();
    clauseSettingsPage(User::factory()->create())->assertNotFound();

    leasesFreshRequest();
    $this->actingAs($agent)->get('/o/acme-homes/leases')->assertOk()->assertDontSee('settings/documents/lease-contract');
});

it('saves the template, audited, and prints it only on contracts issued afterwards', function () {
    $palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court']);
    $first = activeLease($this->acme, $this->owner, unitIn($this->acme, $palm, ['unit_label' => 'Flat 1']), tenantIn($this->acme, ['first_name' => 'Kemi', 'last_name' => 'Adebayo', 'phone' => '+2348031112222', 'email' => 'kemi.adebayo@example.com']));

    clauseSettingsUpdate($this->owner, ['clauses' => "  {tenant_name} keeps the garden tidy.\r\n\r\nNo pets in {unit}.  "], [['save']])
        ->assertOk()->assertSee('New lease contracts will print these clauses');

    $second = activeLease($this->acme, $this->owner, unitIn($this->acme, $palm, ['unit_label' => 'Flat 2']), tenantIn($this->acme, ['first_name' => 'Tunde', 'last_name' => 'Bakare', 'phone' => '+2348039998888', 'email' => 'tunde.bakare@example.com']));
    $audit = AuditEntry::query()->where('log_name', 'settings')->where('event', 'updated')->latest('id')->firstOrFail();

    expect(storedClauses($this->acme))->toBe("{tenant_name} keeps the garden tidy.\n\nNo pets in {unit}.")
        ->and($audit->causer_id)->toBe($this->owner->id)
        ->and($audit->properties['key'])->toBe(ContractClauses::SETTING)
        ->and($audit->properties['new'])->toBe("{tenant_name} keeps the garden tidy.\n\nNo pets in {unit}.")
        ->and(inOrganization($this->acme, fn () => LeaseContract::query()->where('lease_id', $first->id)->value('clauses')))->toBe(ContractClauses::DEFAULT)
        ->and(inOrganization($this->acme, fn () => LeaseContract::query()->where('lease_id', $second->id)->value('clauses')))->toBe("{tenant_name} keeps the garden tidy.\n\nNo pets in {unit}.");
});

it('refuses an empty or too long template, and keeps the one saved', function (string $clauses) {
    $response = clauseSettingsUpdate($this->owner, ['clauses' => $clauses], [['save']])->assertOk();

    expect(LivewireHttp::errors($response))->toHaveKey('clauses')
        ->and(storedClauses($this->acme))->toBe(ContractClauses::DEFAULT)
        ->and(AuditEntry::query()->where('log_name', 'settings')->exists())->toBeFalse();
})->with([
    'empty' => ['   '],
    'too long' => [str_repeat('A clause. ', 1001)],
]);

it('stores {{ }}, @php and HTML exactly as typed and shows them as text in the preview, with a hint for unknown placeholders', function () {
    $template = "{{ 1 + 1 }} and @php echo 'x'; @endphp\n\n<script>alert(1)</script> {favourite_colour} {tenant_name}";

    $page = clauseSettingsUpdate($this->owner, ['clauses' => $template], [['save']])->assertOk();

    expect(storedClauses($this->acme))->toBe($template)
        ->and(LivewireHttp::html($page))->toContain('{{ 1 + 1 }} and @php echo &#039;x&#039;; @endphp', '&lt;script&gt;alert(1)&lt;/script&gt; {favourite_colour} Chiamaka Okafor', 'data-test="unknown-placeholders"', '<code>{favourite_colour}</code>')
        ->and(LivewireHttp::html($page))->not->toContain('<script>alert(1)</script>');
});

it('restores the standard clauses', function () {
    inOrganization($this->acme, fn () => app(SettingsStore::class)->set(ContractClauses::SETTING, 'Our own clause.'));

    clauseSettingsUpdate($this->owner, [], [['restoreDefault']])->assertOk()->assertSee('The standard clauses are back');

    expect(storedClauses($this->acme))->toBe(ContractClauses::DEFAULT)
        ->and(AuditEntry::query()->where('log_name', 'settings')->where('event', 'reset')->exists())->toBeTrue();
});

it('opens read-only while the organization is suspended and saves nothing', function () {
    $snapshot = LivewireHttp::snapshot(clauseSettingsPage($this->owner)->assertOk()->assertSee('Save clauses'));
    $this->acme->forceFill(['status' => OrganizationStatus::Suspended])->save();

    clauseSettingsPage($this->owner)->assertOk()->assertDontSee('Save clauses')->assertSee('You can see these clauses but not change them.');
    leasesFreshRequest();
    $this->actingAs($this->owner);
    LivewireHttp::update(test(), $snapshot, ['clauses' => 'Changed while suspended.'], [['save']])->assertForbidden();
    leasesFreshRequest();
    $this->actingAs($this->owner);
    LivewireHttp::update(test(), $snapshot, [], [['restoreDefault']])->assertForbidden();

    expect(storedClauses($this->acme))->toBe(ContractClauses::DEFAULT);
});

it('never lets the template reach a contract through anything but the whitelisted placeholders', function () {
    inOrganization($this->acme, fn () => app(SettingsStore::class)->set(ContractClauses::SETTING, "Let to {tenant_name} @php echo 'ran'; @endphp {{ \$lease->id }}."));
    $palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court']);
    $lease = activeLease($this->acme, $this->owner, unitIn($this->acme, $palm, ['unit_label' => 'Flat 1']), tenantIn($this->acme, ['first_name' => 'Kemi', 'last_name' => 'Adebayo', 'phone' => '+2348031112222', 'email' => 'kemi.adebayo@example.com']));
    $contract = inOrganization($this->acme, fn () => LeaseContract::query()->where('lease_id', $lease->id)->firstOrFail());

    $html = inOrganization($this->acme, fn () => app(DocumentRenderer::class)->web(app(LeaseContracts::class)->document($contract)));

    expect($html)->toContain('Let to Kemi Adebayo @php echo &#039;ran&#039;; @endphp {{ $lease-&gt;id }}.')
        ->and($html)->not->toContain('ran;', "Let to Kemi Adebayo ran {$lease->id}");
});
