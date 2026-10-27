<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Settings\SettingsStore;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Modules\Billing\Bills\BillService;
use Modules\Billing\Models\Bill;
use Modules\Leasing\Contracts\BillingCycle;
use Modules\Leasing\Contracts\CycleCalculator;
use Modules\Leasing\Models\Lease;
use Tests\Support\LivewireHttp;

/*
 * Helpers for the Billing module's tests (P03-04b). Leases come from the
 * Leasing module's helpers (made through LeaseService), buildings, units
 * and assignments from the Properties module's, tenants from the Tenants
 * module's. Bills are made the way production makes them: by the
 * scheduled `bills-generate` job.
 */

require_once __DIR__.'/../../../Leasing/tests/Feature/helpers.php';
require_once __DIR__.'/../../../../tests/Feature/Exports/helpers.php';

/** The scheduled event of that name (bills-generate, bills-flag-overdue), checked against its cron line. */
function billingScheduledEvent(string $name, string $expression): CallbackEvent
{
    $event = collect(app(Schedule::class)->events())->first(fn (ScheduledEvent $event): bool => $event->description === $name);

    expect($event)->toBeInstanceOf(CallbackEvent::class)
        ->and($event->expression)->toBe($expression);

    return $event;
}

/** Runs `bills-generate` (00:10 daily) as the scheduler does: the job, queued on `billing`, runs inline (sync queue). */
function runBillGeneration(): void
{
    billingScheduledEvent('bills-generate', '10 0 * * *')->run(app());
}

/** Runs `bills-flag-overdue` (01:00 daily) as the scheduler does. */
function runOverdueFlagging(): void
{
    billingScheduledEvent('bills-flag-overdue', '0 1 * * *')->run(app());
}

/** Every bill of the organization (voided too), oldest first. */
function billsOf(Organization $organization): Collection
{
    return inOrganization($organization, fn () => Bill::query()->withTrashed()->orderBy('id')->get());
}

/** The bill as stored now. */
function storedBill(Organization $organization, Bill|int $bill): Bill
{
    return inOrganization($organization, fn (): Bill => Bill::query()->withTrashed()->findOrFail($bill instanceof Bill ? $bill->id : $bill));
}

/**
 * The lease's bills as [period start, period end, due date] rows (Y-m-d), by period.
 *
 * @return list<array{string, string, string}>
 */
function billedPeriodsOf(Organization $organization, Lease $lease): array
{
    return inOrganization($organization, fn (): array => Bill::query()->where('lease_id', $lease->id)->orderBy('period_start')->get()
        ->map(fn (Bill $bill): array => [$bill->period_start?->toDateString(), $bill->period_end?->toDateString(), $bill->due_date->toDateString()])
        ->all());
}

/**
 * The calculator's first $count periods of a lease as [start, end, due] rows (Y-m-d).
 *
 * @return list<array{string, string, string}>
 */
function calculatorPeriods(string $anchor, BillingCycle $cycle, int $count, int $dueDayOffset = 0, int $from = 0): array
{
    $calculator = app(CycleCalculator::class);
    $periods = [];

    for ($index = $from; $index < $from + $count; $index++) {
        $period = $calculator->period(CarbonImmutable::parse($anchor), $cycle, $index);
        $periods[] = [$period->start->toDateString(), $period->end->toDateString(), $calculator->dueDate($period->start, $dueDayOffset)->toDateString()];
    }

    return $periods;
}

/** Sets the organization's lead window (`billing.lead_days`). */
function setLeadDays(Organization $organization, int $days): void
{
    inOrganization($organization, fn () => app(SettingsStore::class)->set(BillService::LEAD_DAYS_SETTING, $days));
}

/** A fresh request, as production starts every one. */
function billsFreshRequest(): void
{
    leasesFreshRequest();
}

/** A page of the bills area, as the user ($path after /bills). */
function billsPage(User $user, string $path = '', string $slug = 'acme-homes'): TestResponse
{
    billsFreshRequest();

    return test()->actingAs($user)->get("/o/{$slug}/bills{$path}");
}

/** The Livewire snapshot of a bills page the user can open. */
function billsSnapshot(User $user, string $path = '', string $slug = 'acme-homes'): string
{
    $snapshot = LivewireHttp::snapshot(billsPage($user, $path, $slug)->assertOk());
    billsFreshRequest();
    test()->actingAs($user);

    return $snapshot;
}

/** A Livewire update on a snapshot, as a fresh request by the same user. */
function billsUpdate(User $user, string $snapshot, array $updates = [], array $calls = []): TestResponse
{
    billsFreshRequest();
    test()->actingAs($user);

    return LivewireHttp::update(test(), $snapshot, $updates, $calls);
}

/** The bill ids a rendered bills list shows, in order. */
function listedBills(TestResponse|string $page): array
{
    preg_match_all('/data-bill="(\d+)"/', htmlOf($page), $matches);

    return array_map('intval', $matches[1]);
}
