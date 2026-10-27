<?php

declare(strict_types=1);

use App\Enums\OrganizationPermission;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\User;
use App\Platform\Customers\OrganizationSuspension;
use App\Tenancy\OrganizationContext;
use App\Tenancy\OrganizationWriteGuard;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Fixtures\Http\WorkspaceWriteProbe;
use Tests\Fixtures\Livewire\WorkspaceProbe;

/*
 * P01-06 acceptance: "Suspending an org blocks its users' write actions
 * within one request; reactivating restores them" (BILLING_PAYMENTS.md § 2,
 * ONBOARDING_COMPLIANCE.md § 3). Members of a suspended organization can
 * still sign in, read and export; every write is refused by the one
 * mechanism in App\Tenancy\OrganizationWriteGuard: plain HTTP, Livewire
 * calls, permissions and policies.
 */

beforeEach(function () {
    Route::middleware(['web', 'auth', 'organization'])->prefix('o/{organization}')->group(function () {
        Route::get('probe', fn () => response()->json(['read' => true]));
        Route::post('probe', [WorkspaceWriteProbe::class, 'store']);
        Route::put('probe', [WorkspaceWriteProbe::class, 'store']);
        Route::patch('probe', [WorkspaceWriteProbe::class, 'store']);
        Route::delete('probe', [WorkspaceWriteProbe::class, 'store']);
        Route::post('probe/export', [WorkspaceWriteProbe::class, 'export']);
        Route::post('probe/closure', fn () => response()->json(['saved' => true]));
        Route::get('probe/live', fn () => Blade::render('<livewire:workspace-probe />'));
    });
    Route::getRoutes()->refreshNameLookups();
    Livewire::component('workspace-probe', WorkspaceProbe::class);

    Notification::fake();
    seedStaffRoles();
    $this->operator = staffNamed('Tunde', ['operations-manager']);
    $this->suspension = app(OrganizationSuspension::class);
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Acme Homes']);
    $this->other = Organization::factory()->create(['slug' => 'other-realty', 'name' => 'Other Realty']);
});

afterEach(fn () => app(OrganizationContext::class)->clear());

function httpWrites(): int
{
    return (int) Cache::store('array')->get('probe.http-writes', 0);
}

function livewireWrites(): int
{
    return (int) Cache::store('array')->get('probe.livewire-writes', 0);
}

/**
 * Loads the probe component's page and sends one Livewire update request
 * to it, as the browser would.
 *
 * @param  list<array{method: string, params: list<mixed>}>  $calls
 * @param  array<string, mixed>  $updates
 */
function livewireUpdate(User $user, string $slug, array $calls, array $updates = []): TestResponse
{
    $page = test()->actingAs($user)->get("/o/{$slug}/probe/live")->assertOk();
    preg_match('/wire:snapshot="([^"]+)"/', (string) $page->getContent(), $match);
    expect($match)->toHaveKey(1);

    // A fresh process in production: nothing left over from the page load,
    // so the update request must find its organization again (persistent
    // middleware).
    app('livewire')->flushState();
    app(OrganizationContext::class)->clear();

    return test()->actingAs($user)
        ->withHeaders(['X-Livewire' => 'true'])
        ->postJson(app('livewire')->getUpdateUri(), ['components' => [[
            'snapshot' => html_entity_decode($match[1], ENT_QUOTES),
            'updates' => (object) $updates,
            'calls' => array_map(fn (array $call): array => $call + ['metadata' => []], $calls),
        ]]]);
}

it('refuses every write from the next request after suspension, and reactivation restores them', function (OrganizationRole $role) {
    $member = joinAs(User::factory()->create(), $this->acme, $role);

    $this->actingAs($member)->postJson('/o/acme-homes/probe')->assertOk();
    expect(httpWrites())->toBe(1);

    $this->suspension->suspend($this->operator, $this->acme, 'Chargeback investigation');

    // The very next request is refused, whatever the method.
    foreach (['postJson', 'putJson', 'patchJson', 'deleteJson'] as $method) {
        $this->actingAs($member)->{$method}('/o/acme-homes/probe')
            ->assertForbidden()
            ->assertJsonPath('message', 'Acme Homes is suspended, so nothing can be changed until it is reactivated. You can still sign in, view and export its data.');
    }

    $this->actingAs($member)->postJson('/o/acme-homes/probe/closure')->assertForbidden();
    expect(httpWrites())->toBe(1);

    // Reading and exporting still work.
    $this->actingAs($member)->getJson('/o/acme-homes/probe')->assertOk()->assertJson(['read' => true]);
    $this->actingAs($member)->postJson('/o/acme-homes/probe/export')->assertOk()->assertJson(['exported' => true]);
    $this->actingAs($member)->get('/o/acme-homes')->assertOk()->assertSee('This organization is suspended');

    $this->suspension->reactivate($this->operator, $this->acme, 'Investigation closed');

    $this->actingAs($member)->postJson('/o/acme-homes/probe')->assertOk();
    expect(httpWrites())->toBe(2);
    $this->actingAs($member)->get('/o/acme-homes')->assertOk()->assertDontSee('This organization is suspended');
})->with([
    'org-owner' => OrganizationRole::Owner,
    'org-admin' => OrganizationRole::Admin,
    'agent' => OrganizationRole::Agent,
]);

it('affects only the suspended organization for a member of two', function () {
    $member = User::factory()->create();
    joinAs($member, $this->acme, OrganizationRole::Owner);
    joinAs($member, $this->other, OrganizationRole::Agent);

    $this->suspension->suspend($this->operator, $this->acme, 'Unpaid beyond grace');

    $this->actingAs($member)->postJson('/o/acme-homes/probe')->assertForbidden();
    $this->actingAs($member)->postJson('/o/other-realty/probe')->assertOk();
    $this->actingAs($member)->getJson('/o/acme-homes/probe')->assertOk();
    $this->actingAs($member)->get('/o/other-realty')->assertOk()->assertDontSee('This organization is suspended');

    $context = app(OrganizationContext::class);
    $member = $member->fresh();

    expect($context->run($this->acme->fresh(), fn () => $member->can(OrganizationPermission::ManageLeases->value)))->toBeFalse()
        ->and($context->run($this->other, fn () => $member->can(OrganizationPermission::ManageLeases->value)))->toBeTrue()
        // Switching between them still works.
        ->and(Gate::forUser($member)->allows('switchTo', $this->acme->fresh()))->toBeTrue();

    $this->actingAs($member)->post('/organizations/acme-homes/switch')->assertRedirect('/o/acme-homes');
});

it('lets members of a suspended organization sign in and read', function () {
    $owner = joinAs(User::factory()->create(), $this->acme);
    $this->suspension->suspend($this->operator, $this->acme, 'Compliance review');

    $this->post(route('login.store'), ['identifier' => $owner->email, 'password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/o/acme-homes');

    $this->get('/o/acme-homes')
        ->assertOk()
        ->assertSee('This organization is suspended')
        ->assertSee('You can still sign in, view and export its data.');
});

it('takes away write permissions and keeps those that read, export or pay', function () {
    $owner = joinAs(User::factory()->create(), $this->acme);
    $this->acme->update(['status' => OrganizationStatus::Suspended]);
    $context = app(OrganizationContext::class);

    $context->run($this->acme, function () use ($owner): void {
        foreach (OrganizationPermission::cases() as $permission) {
            expect($owner->hasPermissionTo($permission->value))->toBe($permission->allowedWhileSuspended(), $permission->value)
                ->and(Gate::forUser($owner)->allows($permission->value))->toBe($permission->allowedWhileSuspended(), $permission->value);
        }

        $refusal = Gate::forUser($owner)->inspect(OrganizationPermission::UpdateOrganization->value);
        expect($refusal->allowed())->toBeFalse()
            ->and($refusal->message())->toContain('Acme Homes is suspended');
    });

    // The same owner keeps everything in an active organization.
    $context->run($this->acme->fresh()->forceFill(['status' => OrganizationStatus::Active]), function () use ($owner): void {
        expect($owner->hasPermissionTo(OrganizationPermission::UpdateOrganization->value))->toBeTrue();
    });
});

it('denies policy abilities that write and allows those that read, before the policy runs', function () {
    $owner = joinAs(User::factory()->create(), $this->acme);
    $this->acme->update(['status' => OrganizationStatus::Suspended]);

    foreach (['viewAny', 'view', 'viewBankAccount', 'exportBills', 'downloadReceipt', 'create', 'update', 'delete', 'confirmPayment', 'renew'] as $ability) {
        Gate::define($ability, fn (): bool => true);
    }

    app(OrganizationContext::class)->run($this->acme, function () use ($owner): void {
        $gate = Gate::forUser($owner);

        foreach (['viewAny', 'view', 'viewBankAccount', 'exportBills', 'downloadReceipt'] as $read) {
            expect($gate->allows($read))->toBeTrue($read);
        }

        foreach (['create', 'update', 'delete', 'confirmPayment', 'renew'] as $write) {
            expect($gate->allows($write))->toBeFalse($write)
                ->and($gate->inspect($write)->message())->toContain('suspended');
        }
    });

    // An organization passed to the check counts even with no context set.
    expect(Gate::forUser($owner)->allows('update', $this->acme->fresh()))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('update', $this->other))->toBeTrue();
});

it('refuses Livewire writes in a suspended organization before they run', function () {
    $agent = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent);
    $save = [['method' => 'save', 'params' => []]];

    // Active: the update request gets its organization context and saves.
    livewireUpdate($agent, 'acme-homes', $save)->assertOk()->assertSee('saves 1');
    expect(livewireWrites())->toBe(1);

    $this->suspension->suspend($this->operator, $this->acme, 'Unpaid beyond grace');

    livewireUpdate($agent, 'acme-homes', $save)->assertForbidden();
    livewireUpdate($agent, 'acme-homes', [['method' => '__dispatch', 'params' => ['probe-save', []]]])->assertForbidden();
    expect(livewireWrites())->toBe(1);

    // Exports, paging, refreshing and typing in a field still work.
    livewireUpdate($agent, 'acme-homes', [['method' => 'export', 'params' => []]])->assertOk()->assertSee('exports 1');
    livewireUpdate($agent, 'acme-homes', [['method' => 'nextPage', 'params' => []]])->assertOk();
    livewireUpdate($agent, 'acme-homes', [['method' => '$refresh', 'params' => []]])->assertOk();
    livewireUpdate($agent, 'acme-homes', [['method' => '__dispatch', 'params' => ['probe-refresh', []]]])->assertOk();
    livewireUpdate($agent, 'acme-homes', [], ['search' => 'Flat 4'])->assertOk();

    $this->suspension->reactivate($this->operator, $this->acme, 'Paid');

    livewireUpdate($agent, 'acme-homes', $save)->assertOk()->assertSee('saves 1');
    expect(livewireWrites())->toBe(2);
});

it('treats a cancelled organization as read-only and a restricted one as writable', function () {
    $owner = joinAs(User::factory()->create(), $this->acme);

    $this->acme->update(['status' => OrganizationStatus::Cancelled]);
    $this->actingAs($owner)->postJson('/o/acme-homes/probe')
        ->assertForbidden()
        ->assertJsonPath('message', 'Acme Homes is closed, so nothing can be changed. You can still view and export its data.');

    // Restriction (ONBOARDING_COMPLIANCE.md § 5.5) narrows only new leases,
    // properties and units; those features enforce it themselves.
    $this->acme->update(['status' => OrganizationStatus::Restricted]);
    $this->actingAs($owner)->postJson('/o/acme-homes/probe')->assertOk();
});

it('keeps exactly the reading, exporting and paying permissions while suspended', function () {
    $kept = array_values(array_map(
        fn (OrganizationPermission $permission): string => $permission->value,
        array_filter(OrganizationPermission::cases(), fn (OrganizationPermission $permission): bool => $permission->allowedWhileSuspended()),
    ));

    expect($kept)->toBe([
        'workspace.access',
        'settings.view',
        'organization.billing',
        'payment_settings.view',
        'compliance.view',
        'organization.activity',
        'members.view',
        'owners.view',
        'properties.view',
        'leases.view',
        'bills.view',
        'payments.view',
        'payments.submit_proof',
        'tickets.view',
        'reports.view',
    ]);
});

it('never touches staff permission checks', function () {
    $this->acme->update(['status' => OrganizationStatus::Suspended]);

    app(OrganizationContext::class)->run($this->acme, function (): void {
        expect(app(OrganizationWriteGuard::class)->blockedOrganization()?->is($this->acme))->toBeTrue()
            ->and(Gate::forUser($this->operator)->allows('customers.suspend'))->toBeTrue();
    });
});
