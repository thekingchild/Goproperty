<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Subscriptions\Jobs\EndTrials;
use App\Subscriptions\SubscriptionService;
use App\Tenancy\OrganizationContext;
use Modules\Properties\Models\Property;
use Modules\Properties\Models\Unit;
use Modules\SaasBilling\Enums\InvoiceKind;
use Modules\SaasBilling\Jobs\MeterUnits;
use Modules\SaasBilling\Models\PlatformInvoice;

/*
 * Helpers for the metering and subscription payment tests (P06-02, P06-03).
 * Need tests/Support/compliance.php (inOrganization) loaded, as Pest.php does.
 */

/** Adds units to a building of the organization; archived ones are made too, and never counted (§ 1.4). */
function withUnits(Organization $organization, int $count): void
{
    inOrganization($organization, function () use ($count): void {
        $building = Property::query()->first() ?? Property::factory()->create();
        Unit::factory()->in($building)->count($count)->create();
        Unit::factory()->in($building)->create()->delete();
    });
}

function meterUnits(): void
{
    app()->call([new MeterUnits, 'handle']);
}

function endTrials(): void
{
    (new EndTrials)->handle(app(OrganizationContext::class), app(SubscriptionService::class));
}

/** @return list<PlatformInvoice> the organization's subscription invoices, oldest first */
function subscriptionInvoices(Organization $organization): array
{
    return inOrganization($organization, fn (): array => array_values(PlatformInvoice::query()->where('kind', InvoiceKind::Subscription)->orderBy('period_start')->get()->all()));
}

/** 7.5 % of the subtotal, rounded to the nearest kobo, halves up: worked out here independently of Money. */
function expectedVat(int $subtotalKobo): int
{
    return intdiv($subtotalKobo * 750 + 5_000, 10_000);
}
