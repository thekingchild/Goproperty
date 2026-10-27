<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Properties\Models\Property;
use Modules\Properties\Models\Unit;
use Modules\SaasBilling\Models\BillingTransferRequest;
use Modules\SaasBilling\Notifications\BillingTransferOffered;
use Modules\SaasBilling\Transfers\BillingTransferService;
use Modules\SaasBilling\Transfers\IncomingTransfers;
use Modules\SaasBilling\Transfers\MonthlyBills;

/*
 * Helpers for the SaasBilling module's billing-transfer tests (P02-04).
 * Offers and answers go through BillingTransferService, in the
 * organization context each side works in, as the pages do. Units are made
 * with the Properties module's factories (test data only: the module's
 * code reads units through UnitDirectory). inOrganization() comes from
 * tests/Support/compliance.php.
 */

/** Gives the organization this many live units in one building. */
function billableUnits(Organization $organization, int $count): void
{
    inOrganization($organization, function () use ($count): void {
        $building = Property::factory()->create();
        Unit::factory()->in($building)->count($count)->create();
    });
}

/** The offering organization's current monthly bill (kobo, before VAT). */
function monthlyBillOf(Organization $organization): Money
{
    return app(MonthlyBills::class)->for($organization)->amount;
}

/** $by (in $from) offers $from's bill to $to. */
function offerBill(User $by, Organization $from, Organization $to): BillingTransferRequest
{
    return inOrganization($from, fn (): BillingTransferRequest => app(BillingTransferService::class)->initiate($by, $to));
}

/** The offer as the receiving organization sees it (null when not addressed to it). */
function incomingOffer(Organization $to, BillingTransferRequest|int $transfer): ?BillingTransferRequest
{
    return inOrganization($to, fn (): ?BillingTransferRequest => app(IncomingTransfers::class)->find($transfer instanceof BillingTransferRequest ? $transfer->id : $transfer));
}

/** $by (in $to) accepts, at the amount the accept screen shows now unless given. */
function acceptOffer(User $by, Organization $to, BillingTransferRequest $transfer, ?Money $shown = null): BillingTransferRequest
{
    $shown ??= monthlyBillOf(Organization::query()->findOrFail($transfer->organization_id));

    return inOrganization($to, fn (): BillingTransferRequest => app(BillingTransferService::class)->accept($by, $transfer, $shown));
}

function declineOffer(User $by, Organization $to, BillingTransferRequest $transfer): BillingTransferRequest
{
    return inOrganization($to, fn (): BillingTransferRequest => app(BillingTransferService::class)->decline($by, $transfer));
}

function withdrawOffer(User $by, Organization $from, BillingTransferRequest $transfer): BillingTransferRequest
{
    return inOrganization($from, fn (): BillingTransferRequest => app(BillingTransferService::class)->cancel($by, $transfer));
}

/** The row as stored now (read in its own organization). */
function storedTransfer(BillingTransferRequest $transfer): BillingTransferRequest
{
    $from = Organization::query()->findOrFail($transfer->organization_id);

    return inOrganization($from, fn (): BillingTransferRequest => BillingTransferRequest::query()->findOrFail($transfer->id));
}

/** A fresh request, as production starts every one. */
function transfersFreshRequest(): void
{
    config(['auth.defaults.guard' => 'web']);
    app('auth')->forgetGuards();
    app()->forgetScopedInstances();
    app('livewire')->flushState();
    app(OrganizationContext::class)->clear();
}

/** A billing-transfers page as this user. */
function transfersPage(User $user, string $slug, string $path = ''): TestResponse
{
    transfersFreshRequest();

    return test()->actingAs($user)->get("/o/{$slug}/billing-transfers{$path}");
}

/** The token in the newest offer email to this user. */
function offerToken(User $owner): string
{
    $sent = Notification::sent($owner, BillingTransferOffered::class);
    expect($sent)->not->toBeEmpty();
    $last = $sent->last();
    assert($last instanceof BillingTransferOffered);

    return Str::afterLast($last->url(), '/');
}
