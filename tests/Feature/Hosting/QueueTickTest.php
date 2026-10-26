<?php

declare(strict_types=1);

use App\Hosting\Heartbeats;
use App\Hosting\Queue\QueueTick;
use App\Hosting\Queue\TickResult;
use App\Hosting\Queue\TickStopReason;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Tests\Fixtures\Jobs\RecordingJob;

beforeEach(function () {
    RecordingJob::$ran = [];
    // A long parallel run can pass the tick's 192 MB memory guard before
    // these tests start; the guard itself is not what they test.
    config(['hosting.queue_tick.memory_mb' => 2048]);
    CarbonImmutable::setTestNow('2026-10-26 09:00:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function pushJob(string $queue, string $label, int $takesSeconds = 0, bool $fails = false): void
{
    RecordingJob::dispatch($label, $takesSeconds, $fails)->onConnection('database')->onQueue($queue);
}

function tick(): TickResult
{
    return app(QueueTick::class)->run();
}

it('drains every lane from the database queue and records a heartbeat', function () {
    pushJob('billing', 'payment');
    pushJob('notifications', 'email');
    pushJob('default', 'webhook');
    pushJob('pdf', 'invoice-pdf');
    pushJob('imports', 'import-chunk');
    pushJob('reports', 'not-a-lane');

    $result = tick();

    expect($result->stoppedBy)->toBe(TickStopReason::Drained)
        ->and($result->processed)->toBe(['payments' => 1, 'notifications' => 1, 'default' => 1, 'bulk' => 2])
        ->and(RecordingJob::$ran)->toEqualCanonicalizing(['payment', 'email', 'webhook', 'invoice-pdf', 'import-chunk'])
        // Queues outside the lanes are left alone.
        ->and(DB::table('jobs')->pluck('queue')->all())->toBe(['reports']);

    $heartbeat = app(Heartbeats::class)->last(Heartbeats::QUEUE_TICK);

    expect($heartbeat)->toMatchArray(['stopped_by' => 'drained', 'total' => 5])
        ->and(app(Heartbeats::class)->lastAt(Heartbeats::QUEUE_TICK)?->toDateTimeString())->toBe('2026-10-26 09:00:00');
});

it('takes one job per lane in turn so a long lane cannot starve the others', function () {
    foreach ([1, 2, 3] as $n) {
        pushJob('pdf', "pdf-{$n}");
    }

    foreach ([1, 2, 3] as $n) {
        pushJob('notifications', "sms-{$n}");
    }

    tick();

    expect(RecordingJob::$ran)->toBe(['sms-1', 'pdf-1', 'sms-2', 'pdf-2', 'sms-3', 'pdf-3']);
});

it('caps each lane per tick and leaves the rest for the next minute', function () {
    config(['hosting.queue_tick.lanes.bulk.max_jobs' => 2]);

    foreach (range(1, 5) as $n) {
        pushJob('pdf', "pdf-{$n}");
    }

    $result = tick();

    expect($result->processed['bulk'])->toBe(2)
        ->and($result->stoppedBy)->toBe(TickStopReason::Drained)
        ->and(DB::table('jobs')->count())->toBe(3);
});

it('stops starting jobs once the time budget is spent', function () {
    config(['hosting.queue_tick.budget_seconds' => 45]);

    foreach (range(1, 5) as $n) {
        pushJob('default', "job-{$n}", takesSeconds: 20);
    }

    $result = tick();

    // Starts at 0 s, 20 s and 40 s; at 60 s the budget is gone.
    expect($result->stoppedBy)->toBe(TickStopReason::Budget)
        ->and($result->total())->toBe(3)
        ->and(DB::table('jobs')->count())->toBe(2);
});

it('reports a failing job and carries on with the next one', function () {
    Exceptions::fake();
    config(['hosting.queue_tick.tries' => 1]);

    pushJob('default', 'broken', fails: true);
    pushJob('default', 'healthy');

    $result = tick();

    Exceptions::assertReported(RuntimeException::class);
    expect(RecordingJob::$ran)->toBe(['healthy'])
        ->and($result->processed['default'])->toBe(2)
        ->and(DB::table('failed_jobs')->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('releases a failing job for a retry while tries remain', function () {
    Exceptions::fake();
    config(['hosting.queue_tick.tries' => 3, 'hosting.queue_tick.backoff' => 30]);

    pushJob('default', 'flaky', fails: true);

    tick();

    $job = DB::table('jobs')->first();

    expect($job)->not->toBeNull()
        ->and($job->attempts)->toBe(1)
        ->and($job->available_at)->toBe(CarbonImmutable::now()->addSeconds(30)->getTimestamp())
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

it('does nothing while another tick holds the lock', function () {
    pushJob('default', 'waiting');
    $lock = Cache::lock('hosting:queue-tick', 60);
    $lock->get();

    $result = tick();

    expect($result->stoppedBy)->toBe(TickStopReason::Locked)
        ->and(RecordingJob::$ran)->toBe([])
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and(app(Heartbeats::class)->last(Heartbeats::QUEUE_TICK))->toBeNull();

    $lock->release();

    expect(tick()->total())->toBe(1);
});

it('releases its lock when it finishes', function () {
    tick();

    expect(Cache::lock('hosting:queue-tick', 60)->get())->toBeTrue();
});

it('only drains a database queue connection', function () {
    config(['hosting.queue_tick.connection' => 'sync']);

    tick();
})->throws(RuntimeException::class, 'drains a "database" queue connection only');

it('rejects a lane without queues or a cap', function (array $lane) {
    config(['hosting.queue_tick.lanes' => ['broken' => $lane]]);

    tick();
})->with([
    'no queues' => [['queues' => [], 'max_jobs' => 5]],
    'no cap' => [['queues' => ['default']]],
    'zero cap' => [['queues' => ['default'], 'max_jobs' => 0]],
])->throws(RuntimeException::class, 'Queue tick lane "broken"');
