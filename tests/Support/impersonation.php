<?php

declare(strict_types=1);

use App\Models\ApprovalRequest;
use App\Models\Organization;
use App\Models\PlatformStaff;
use App\Models\User;
use App\Platform\Approvals\ApprovalService;
use App\Platform\Impersonation\HandOff;
use App\Platform\Impersonation\ImpersonationMode;
use App\Platform\Impersonation\Impersonations;
use Illuminate\Testing\TestResponse;

/*
 * Helpers for the impersonation tests (P01-08, PLATFORM_CONSOLE.md § 6).
 * Required by the files in tests/Feature/Impersonation.
 */

/** Starts a support session through the service, as the console does. */
function startSupport(PlatformStaff $staff, User $user, Organization $organization, ImpersonationMode $mode = ImpersonationMode::ReadOnly, string $reason = 'Ticket #4412: tenant cannot see the March bill', ?ApprovalRequest $approval = null): HandOff
{
    if ($mode === ImpersonationMode::Write && $approval === null) {
        $approval = approvedWrite($staff, $user, $organization, $reason);
    }

    return app(Impersonations::class)->start($staff, $user, $organization, $mode, $reason, $approval);
}

/** A write-mode request, approved by a second super-admin (two-person rule). */
function approvedWrite(PlatformStaff $staff, User $user, Organization $organization, string $reason = 'Ticket #4412: fix the tenant\'s phone number'): ApprovalRequest
{
    $request = app(Impersonations::class)->requestWrite($staff, $user, $organization, $reason);
    $approver = PlatformStaff::query()->where('email', 'approver@goproperty.ng')->first()
        ?? staffMember(['name' => 'Bola Approver', 'email' => 'approver@goproperty.ng']);

    return app(ApprovalService::class)->approve($request, $approver, 'OK for 30 minutes');
}

/**
 * Each request in production runs in a fresh process: nothing a guard,
 * context or scoped service remembered from the last request survives.
 * The session (kept by the test's session store) does.
 */
function freshRequest(): void
{
    // actingAs($staff, 'staff') makes `staff` the default guard; customer pages use `web`.
    config(['auth.defaults.guard' => 'web']);
    app('auth')->forgetGuards();
    app()->forgetScopedInstances();
    app('livewire')->flushState();
}

/** Opens the hand-off link in the "browser" and returns the reply. */
function enterSupport(HandOff $handOff): TestResponse
{
    freshRequest();

    return test()->get($handOff->url);
}

/** A request in the impersonating browser (customer cookie only). */
function asSupport(): mixed
{
    freshRequest();

    return test();
}
