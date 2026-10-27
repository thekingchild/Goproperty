<?php

declare(strict_types=1);

use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Testing\TestResponse;
use Tests\Support\LivewireHttp;

/*
 * Helpers for the tenant pages' tests (P03-01a), required by the files in
 * this folder. Pages and actions go through HTTP and Livewire updates, as
 * the browser does. tenantIn(), tenantsOf() and tenantPhone() come from the
 * module's import helpers; buildings and assignments from the Properties
 * module's helpers.
 */

require_once __DIR__.'/../helpers.php';
require_once __DIR__.'/../../../../Properties/tests/Feature/Assignments/helpers.php';
require_once __DIR__.'/../../../../../tests/Support/impersonation.php';

/** A fresh request, as production starts every one. */
function tenantPagesFreshRequest(): void
{
    config(['auth.defaults.guard' => 'web']);
    app('auth')->forgetGuards();
    app()->forgetScopedInstances();
    app('livewire')->flushState();
    app(OrganizationContext::class)->clear();
}

/** A page of the tenants area, as the user ($path after /tenants). */
function tenantsPage(User $user, string $path = '', string $slug = 'acme-homes'): TestResponse
{
    tenantPagesFreshRequest();

    return test()->actingAs($user)->get("/o/{$slug}/tenants{$path}");
}

/** The Livewire snapshot of a tenants page the user can open. */
function tenantsSnapshot(User $user, string $path = '', string $slug = 'acme-homes'): string
{
    $snapshot = LivewireHttp::snapshot(tenantsPage($user, $path, $slug)->assertOk());
    // The update that follows is a new request, by the same signed-in user.
    tenantPagesFreshRequest();
    test()->actingAs($user);

    return $snapshot;
}

/** Form fields for a tenant, with changes. */
function tenantForm(array $changes = []): array
{
    return $changes + [
        'firstName' => 'Adaeze',
        'lastName' => 'Okafor',
        'phone' => '0803 123 4567',
        'email' => 'Adaeze@Example.ng',
        'address' => '12 Allen Avenue, Ikeja',
        'occupation' => 'Accountant',
        'kinName' => 'Chidi Okafor',
        'kinPhone' => '0805 987 6543',
        'kinRelationship' => 'Brother',
        'idType' => 'nin',
        'idValue' => '123 4567 8901',
        'kycStatus' => 'unverified',
    ];
}
