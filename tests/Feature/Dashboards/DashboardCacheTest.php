<?php

declare(strict_types=1);

use App\Dashboards\DashboardCache;
use App\Dashboards\DashboardGenerations;
use App\Dashboards\ReportConnection;
use App\Models\Organization;
use App\Support\Money;
use App\Tenancy\Moves\BuildingRecordMovers;
use App\Tenancy\Moves\MoveDirection;
use App\Tenancy\Moves\OrganizationMoves;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
 * P08-04 (ARCHITECTURE.md § 13): Core's cached dashboard figures — the
 * organization's generation (renewed after a write commits, never by a
 * rolled-back one), entries kept per organization and read back only at
 * their generation, signed and limited to the classes named, and the
 * read-replica-ready connection the figures are worked out on. The
 * Reports page's behaviour is in Modules/Reports/tests/Feature/CachedReportsTest.php.
 */

/** Money worked out by $compute through the cache, counting how often it ran. */
function cachedMoney(int $organizationId, int &$runs, int $kobo = 500, string $name = 'test.figure', array $classes = [Money::class]): Money
{
    return app(DashboardCache::class)->remember($organizationId, $name, ['slot'], ['a' => 1], Money::class, $classes, function () use (&$runs, $kobo): Money {
        $runs++;

        return Money::kobo($kobo);
    });
}

it('works figures out once and reads them back for five minutes, per organization', function () {
    $this->freezeTime();
    $runs = 0;

    expect(cachedMoney(1, $runs)->kobo)->toBe(500)
        ->and(cachedMoney(1, $runs, 900)->kobo)->toBe(500)
        ->and($runs)->toBe(1);

    // Another organization never reads the first one's entry.
    expect(cachedMoney(2, $runs, 900)->kobo)->toBe(900)
        ->and($runs)->toBe(2);

    // After five minutes the entry is gone.
    $this->travel(DashboardCache::TTL_SECONDS + 1)->seconds();
    expect(cachedMoney(1, $runs, 700)->kobo)->toBe(700)
        ->and($runs)->toBe(3);
});

it('keeps one entry per slot: figures for other dates replace it, so the store never fills up with them', function () {
    $runs = 0;
    $for = function (string $day) use (&$runs): Money {
        return app(DashboardCache::class)->remember(1, 'test.day', ['every building'], ['day' => $day], Money::class, [Money::class], function () use (&$runs, $day): Money {
            $runs++;

            return Money::kobo((int) str_replace('-', '', $day));
        });
    };

    expect($for('2027-01-31')->kobo)->toBe(20270131)
        ->and($for('2027-01-31')->kobo)->toBe(20270131)
        ->and($for('2027-02-01')->kobo)->toBe(20270201)
        ->and($for('2027-01-31')->kobo)->toBe(20270131)
        ->and($runs)->toBe(3)
        ->and(DB::table('cache')->where('key', 'like', '%dashboards:1:test.day:%')->count())->toBe(1);
});

it('renews an organization\'s generation once its write commits, once per transaction, and never for a rollback', function () {
    $generations = app(DashboardGenerations::class);
    $runs = 0;
    cachedMoney(1, $runs);
    $before = $generations->current(1)->token;
    $otherOrganization = $generations->current(2)->token;

    // Inside the transaction the figures are still the committed ones.
    DB::transaction(function () use ($generations, $before, &$runs) {
        $generations->changed(1);
        $generations->changed(1);
        expect($generations->current(1)->token)->toBe($before)
            ->and(cachedMoney(1, $runs, 900)->kobo)->toBe(500);
    });

    $after = $generations->current(1)->token;
    expect($after)->not->toBe($before)
        ->and(cachedMoney(1, $runs, 900)->kobo)->toBe(900)
        ->and($runs)->toBe(2);

    // A rolled-back write changes nothing; the next committed one still does.
    DB::beginTransaction();
    $generations->changed(1);
    DB::rollBack();
    expect($generations->current(1)->token)->toBe($after);

    $generations->changed(1);
    expect($generations->current(1)->token)->not->toBe($after)
        // Another organization's generation is its own.
        ->and($generations->current(2)->token)->toBe($otherOrganization);
});

it('reads back only entries signed with the application key', function () {
    $runs = 0;
    cachedMoney(1, $runs);

    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    expect(cachedMoney(1, $runs, 900)->kobo)->toBe(900)
        ->and($runs)->toBe(2);
});

it('does not cache figures holding a class it was not told of, or larger than the limit', function () {
    $runs = 0;
    $holder = function () use (&$runs): ArrayObject {
        return app(DashboardCache::class)->remember(1, 'test.holder', [], [], ArrayObject::class, [ArrayObject::class], function () use (&$runs): ArrayObject {
            $runs++;

            return new ArrayObject(['when' => CarbonImmutable::now()]);
        });
    };

    $holder();
    $holder();
    expect($runs)->toBe(2);

    // Told of both, it caches them (and reads them back whole).
    $listed = function () use (&$runs): ArrayObject {
        return app(DashboardCache::class)->remember(1, 'test.listed', [], [], ArrayObject::class, [ArrayObject::class, CarbonImmutable::class], function () use (&$runs): ArrayObject {
            $runs++;

            return new ArrayObject(['when' => CarbonImmutable::parse('2027-01-31 23:58', 'Africa/Lagos')]);
        });
    };
    $listed();
    expect($listed()['when']->toIso8601String())->toBe('2027-01-31T23:58:00+01:00')
        ->and($runs)->toBe(3);
    $runs = 2;

    $big = function () use (&$runs): stdClass {
        return app(DashboardCache::class)->remember(1, 'test.big', [], [], stdClass::class, [stdClass::class], function () use (&$runs): stdClass {
            $runs++;

            return (object) ['blob' => random_bytes(DashboardCache::MAX_BYTES)];
        });
    };

    $big();
    $big();
    expect($runs)->toBe(4);
});

it('reads from the main connection when no replica is configured, and keeps the default connection as it was', function () {
    $main = DB::getDefaultConnection();
    $connection = app(ReportConnection::class);
    $generation = app(DashboardGenerations::class)->current(1);

    expect($connection->replicaConfigured())->toBeFalse()
        ->and($connection->nameFor($generation))->toBe($main)
        ->and($connection->run($generation, fn (): string => DB::getDefaultConnection()))->toBe($main);
});

it('reads from the replica connection once the organization\'s last write is old enough, from the main one before, and switches back', function () {
    $main = DB::getDefaultConnection();
    config([
        'database.reports.read_host' => config("database.connections.{$main}.host"),
        'database.reports.primary_after_write' => 60,
    ]);
    $this->freezeTime();
    $connection = app(ReportConnection::class);
    $generation = app(DashboardGenerations::class)->renew(1);

    // Just after a write: the main database (a lagging replica may not hold it yet).
    expect($connection->nameFor($generation))->toBe($main);

    // A minute later: the `reports` connection, its reads from the replica, sticky, writes to the main database.
    $this->travel(61)->seconds();
    $connections = [];
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$connections) {
        $connections[] = $query->connectionName;
    });

    expect($connection->run($generation, fn (): array => [DB::getDefaultConnection(), DB::select('select 1 as one')[0]->one]))->toEqual([ReportConnection::NAME, 1])
        ->and($connections)->toBe([ReportConnection::NAME])
        ->and(DB::getDefaultConnection())->toBe($main)
        ->and(config('database.connections.reports.sticky'))->toBeTrue()
        ->and(config('database.connections.reports.read.host'))->toBe(config("database.connections.{$main}.host"))
        ->and(config('database.connections.reports.write'))->toBe([]);

    // A failing report still leaves the default connection as it was.
    expect(fn () => $connection->run($generation, fn () => throw new RuntimeException('report failed')))->toThrow(RuntimeException::class)
        ->and(DB::getDefaultConnection())->toBe($main);
});

it('renews both organizations\' generations when buildings move between them (the movers write by query)', function () {
    $from = Organization::factory()->create();
    $to = Organization::factory()->create();
    app()->instance(BuildingRecordMovers::class, new BuildingRecordMovers(app()));
    $generations = app(DashboardGenerations::class);
    $before = [$generations->current($from->id)->token, $generations->current($to->id)->token];

    DB::transaction(fn () => app(OrganizationMoves::class)->run($from, $to, MoveDirection::Handover, [1], 1, $from, null));

    expect($generations->current($from->id)->token)->not->toBe($before[0])
        ->and($generations->current($to->id)->token)->not->toBe($before[1]);
});
