<?php

declare(strict_types=1);

use App\Hosting\Heartbeats;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Jobs\RecordingJob;

/** @return list<Event> */
function scheduledEvents(): array
{
    return app(Schedule::class)->events();
}

function scheduledEvent(string $name): Event
{
    $event = collect(scheduledEvents())->first(fn (Event $event): bool => $event->description === $name);

    expect($event)->not->toBeNull("No scheduled event named [{$name}].");

    return $event;
}

it('runs every scheduled event inside the cron process', function () {
    // Schedule::command() and runInBackground() start a shell through
    // proc_open, which shared hosts may disable (INSTALLER.md § 8).
    foreach (scheduledEvents() as $event) {
        expect($event)->toBeInstanceOf(CallbackEvent::class, "[{$event->description}] must use Schedule::call() or Schedule::job().")
            ->and($event->runInBackground)->toBeFalse();
    }
});

it('schedules the heartbeat and the queue tick every minute, tick last', function () {
    $names = array_map(fn (Event $event) => $event->description, scheduledEvents());

    expect($names)->toContain('scheduler-heartbeat')
        ->and(end($names))->toBe('queue-tick')
        ->and(scheduledEvent('scheduler-heartbeat')->expression)->toBe('* * * * *')
        ->and(scheduledEvent('queue-tick')->expression)->toBe('* * * * *');
});

it('skips the queue tick when it is switched off', function (bool $enabled) {
    config(['hosting.queue_tick.enabled' => $enabled]);

    expect(scheduledEvent('queue-tick')->filtersPass(app()))->toBe($enabled);
})->with([true, false]);

it('drains the queue and writes both heartbeats from one schedule:run', function () {
    RecordingJob::$ran = [];
    RecordingJob::dispatch('from-cron')->onConnection('database')->onQueue('notifications');

    $this->artisan('schedule:run')->assertSuccessful();

    expect(RecordingJob::$ran)->toBe(['from-cron'])
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(app(Heartbeats::class)->lastAt(Heartbeats::SCHEDULER))->not->toBeNull()
        ->and(app(Heartbeats::class)->last(Heartbeats::QUEUE_TICK))->toMatchArray(['total' => 1]);
});
