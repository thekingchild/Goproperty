<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Modules\Leasing\Leases\LeaseService;
use Modules\Leasing\Models\Lease;
use Modules\Leasing\Models\LeaseDepositEntry;
use Modules\Properties\Enums\UnitStatus;
use Modules\Properties\Models\Unit;
use Modules\Tenants\Models\TenantProfile;
use Tests\Support\LivewireHttp;

/*
 * Helpers for the Leasing module's lease tests (P03-03a). Leases are made
 * through the real writer (Leases\LeaseService), pages through HTTP and
 * Livewire updates. Buildings, units and assignments come from the
 * Properties module's helpers, tenants from the Tenants module's.
 */

require_once __DIR__.'/../../../Properties/tests/Feature/Assignments/helpers.php';
require_once __DIR__.'/../../../Tenants/tests/Feature/Pages/helpers.php';
require_once __DIR__.'/../../../../tests/Support/impersonation.php';

/** The lease form's fields for a yearly lease from 1 Jan 2027, with changes. */
function leaseInput(array $changes = []): array
{
    return $changes + [
        'billingCycle' => 'annual',
        'startDate' => '2027-01-01',
        'endDate' => '2027-12-31',
        'rent' => '1,500,000',
        'deposit' => '150,000',
        'dueDayOffset' => '0',
        'paidUntil' => '',
        'autoRenew' => false,
        'notes' => '',
    ];
}

/** A draft lease of the unit for the tenant, made by the member through LeaseService. */
function draftLease(Organization $organization, User $by, Unit $unit, TenantProfile $tenant, array $changes = []): Lease
{
    return inOrganization($organization, fn (): Lease => app(LeaseService::class)->draft($by, ['tenantId' => $tenant->id, 'unitId' => $unit->id] + leaseInput($changes)));
}

/** Drafts and activates a lease. */
function activeLease(Organization $organization, User $by, Unit $unit, TenantProfile $tenant, array $changes = []): Lease
{
    $lease = draftLease($organization, $by, $unit, $tenant, $changes);

    return inOrganization($organization, fn (): Lease => app(LeaseService::class)->activate($by, $lease));
}

/** The lease as stored now. */
function storedLease(Organization $organization, Lease $lease): Lease
{
    return inOrganization($organization, fn (): Lease => Lease::query()->withTrashed()->findOrFail($lease->id));
}

/** Every lease of the organization, oldest first. */
function leasesOf(Organization $organization): Collection
{
    return inOrganization($organization, fn () => Lease::query()->withTrashed()->orderBy('id')->get());
}

/** The unit's status as stored now. */
function unitStatusOf(Organization $organization, Unit $unit): UnitStatus
{
    return inOrganization($organization, fn (): UnitStatus => Unit::query()->withTrashed()->findOrFail($unit->id)->status);
}

/** The lease's deposit entries as [type, kobo] pairs, oldest first. */
function depositEntriesOf(Organization $organization, Lease $lease): array
{
    return inOrganization($organization, fn (): array => LeaseDepositEntry::query()->where('lease_id', $lease->id)->orderBy('id')->get()
        ->map(fn (LeaseDepositEntry $entry): array => [$entry->type->value, $entry->amount_kobo->kobo])->all());
}

/** A fresh request, as production starts every one. */
function leasesFreshRequest(): void
{
    config(['auth.defaults.guard' => 'web']);
    app('auth')->forgetGuards();
    app()->forgetScopedInstances();
    app('livewire')->flushState();
    app(OrganizationContext::class)->clear();
}

/** A page of the leases area, as the user ($path after /leases). */
function leasesPage(User $user, string $path = '', string $slug = 'acme-homes'): TestResponse
{
    leasesFreshRequest();

    return test()->actingAs($user)->get("/o/{$slug}/leases{$path}");
}

/** The Livewire snapshot of a leases page the user can open. */
function leasesSnapshot(User $user, string $path = '', string $slug = 'acme-homes'): string
{
    $snapshot = LivewireHttp::snapshot(leasesPage($user, $path, $slug)->assertOk());
    leasesFreshRequest();
    test()->actingAs($user);

    return $snapshot;
}

/** A Livewire update on a snapshot, as a fresh request by the same user. */
function leasesUpdate(User $user, string $snapshot, array $updates = [], array $calls = []): TestResponse
{
    leasesFreshRequest();
    test()->actingAs($user);

    return LivewireHttp::update(test(), $snapshot, $updates, $calls);
}

/** The lease ids a rendered leases list shows, in order. */
function listedLeases(TestResponse|string $page): array
{
    preg_match_all('/data-lease="(\d+)"/', htmlOf($page), $matches);

    return array_map('intval', $matches[1]);
}
