<?php

declare(strict_types=1);

use App\Backup\BackupDestination;
use App\Backup\BackupRun;
use App\Backup\Operations\BackupOperation;
use App\Backup\Operations\OperationKind;
use App\Backup\Operations\OperationStatus;
use App\Hosting\Heartbeats;
use App\Models\Organization;
use App\Models\StaffStatus;
use App\Platform\Filament\Widgets\Operations\SchedulerWidget;
use App\Platform\Operations\Alerts\AlertCheck;
use App\Platform\Operations\Alerts\AlertMonitor;
use App\Platform\Operations\Alerts\AlertRule;
use App\Platform\Operations\Alerts\AlertSettings;
use App\Platform\Operations\Alerts\Notices\AlertNotifier;
use App\Platform\Operations\Alerts\Notices\NoticeKind;
use App\Platform\Operations\Alerts\Notices\OperationsAlertMail;
use App\Platform\Operations\Alerts\OperationsAlert;
use App\Platform\Operations\Alerts\Watchdog;
use App\Platform\Operations\Health\ErrorCounter;
use App\Platform\Operations\OperationsAudit;
use App\Platform\Operations\Scheduler\ScheduledTasks;
use App\Settings\SettingsStore;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * P08-03: the operations alerts (PLATFORM_CONSOLE.md § 2 "Alerts", "Who
 * notices a stopped scheduler"; docs/plans/P08-subtasks.md defaults 29–49).
 * Acceptance: "A stopped scheduler raises an alert within the configured
 * window". Slack is faked (Http::fake) in a production environment, the
 * only one that calls it; emails are faked (Notification::fake).
 */

const SLACK_URL = 'https://hooks.slack.com/services/T000/B000/abcdefghijklmnop1234';

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('console'));
    seedStaffRoles();
    Notification::fake();
    $this->slackStatus = 200;
    Http::fake(['hooks.slack.com/*' => fn () => Http::response($this->slackStatus === 200 ? 'ok' : 'no_team', $this->slackStatus)]);
    app()->detectEnvironment(fn (): string => 'production');
    app(SettingsStore::class)->set('operations.alert_slack_webhook_url', SLACK_URL);

    // Emailed by default: active staff holding system.status (technical-admin, super-admin).
    $this->tech = staffNamed('Tobi', ['technical-admin']);
    $this->boss = staffNamed('Ada', ['super-admin']);
    $this->analyst = staffNamed('Kemi', ['analyst']);
});

/** The Slack messages posted so far. */
function slackPosts(): array
{
    return Http::recorded()->map(fn (array $pair): string => (string) ($pair[0]->data()['text'] ?? ''))->values()->all();
}

/** The alert emails each staff member received, as [kind, rule]. */
function alertMails(object $staff): array
{
    return Notification::sent($staff, OperationsAlertMail::class)
        ->map(fn (OperationsAlertMail $mail): string => $mail->notice->kind->value.':'.($mail->notice->rule?->value ?? ''))
        ->values()
        ->all();
}

function uptimeToken(): string
{
    $token = str_repeat('a1B2', 10);
    app(SettingsStore::class)->set('operations.uptime_token_hash', hash('sha256', $token));

    return $token;
}

describe('acceptance: a stopped scheduler raises an alert within the configured window', function () {
    it('raises the alert once the heartbeat is older than the window, not before, reminds only after the repeat interval, and says when it cleared', function (int $window) {
        app(SettingsStore::class)->set('operations.alert_scheduler_minutes', $window);
        $token = uptimeToken();
        $start = CarbonImmutable::parse('2026-11-10 09:01:00', 'UTC');
        $this->travelTo($start);

        // The scheduler runs (cron's schedule:run): the heartbeat, then the minute's checks.
        $this->artisan('schedule:run')->assertSuccessful();
        expect(app(Heartbeats::class)->lastAt(Heartbeats::SCHEDULER)?->equalTo($start))->toBeTrue();

        // Then cron stops: nothing runs the scheduler any more. Visitors and the
        // outside uptime monitor keep calling, once a minute.
        for ($minute = 1; $minute <= $window; $minute++) {
            $this->travelTo($start->addMinutes($minute));
            $this->getJson('/up/scheduler/'.$token)->assertOk()->assertJsonPath('scheduler', 'running');
        }

        // Not before the window: no incident, nothing sent.
        expect(OperationsAlert::query()->count())->toBe(0)
            ->and(slackPosts())->toBe([]);
        Notification::assertNothingSent();

        // The first minute past the window: the uptime monitor gets its 503, and the
        // request's watchdog raises the console's own alert, to Slack and by email.
        $raisedAt = $start->addMinutes($window + 1);
        $this->travelTo($raisedAt);
        $this->getJson('/up/scheduler/'.$token)->assertStatus(503)->assertJsonPath('scheduler', 'stopped');

        $incident = OperationsAlert::query()->sole();
        expect($incident->rule)->toBe(AlertRule::SchedulerStopped)
            ->and($incident->isOpen())->toBeTrue()
            ->and($incident->raised_by)->toBe('uptime')
            ->and($incident->started_at?->equalTo($raisedAt))->toBeTrue()
            // Within the window: the first missed run was a minute after the last one.
            ->and($start->addMinute()->diffInMinutes($raisedAt))->toBeLessThanOrEqual($window)
            ->and(slackPosts())->toHaveCount(1)
            ->and(slackPosts()[0])->toContain('Alert: Scheduler stopped')->toContain('has not run since 10 Nov 2026, 10:01 (Lagos)')
            ->and(alertMails($this->tech))->toBe(['raised:scheduler_stopped'])
            ->and(alertMails($this->boss))->toBe(['raised:scheduler_stopped'])
            ->and(alertMails($this->analyst))->toBe([]);
        Http::assertSent(fn (HttpRequest $request): bool => $request->url() === SLACK_URL);

        // Every minute after that, for the next hour: still stopped, never sent again.
        for ($minute = $window + 2; $minute < $window + 1 + 60; $minute++) {
            $this->travelTo($start->addMinutes($minute));
            $this->get('/up/scheduler/'.$token)->assertStatus(503);
        }

        expect(slackPosts())->toHaveCount(1)
            ->and(alertMails($this->tech))->toHaveCount(1)
            ->and(OperationsAlert::query()->count())->toBe(1);

        // An hour after the first notice (the default repeat interval): one reminder.
        $this->travelTo($raisedAt->addMinutes(60));
        $this->get('/up/scheduler/'.$token)->assertStatus(503);

        expect(slackPosts())->toHaveCount(2)
            ->and(slackPosts()[1])->toContain('Still failing: Scheduler stopped')->toContain('Open since')
            ->and(alertMails($this->tech))->toBe(['raised:scheduler_stopped', 'reminder:scheduler_stopped'])
            ->and($incident->fresh()?->notifications)->toBe(2);

        // Cron is fixed: the scheduler's own run clears the alert, with one notice.
        $this->travelTo($raisedAt->addMinutes(64));
        $this->artisan('schedule:run')->assertSuccessful();

        expect($incident->fresh()?->isOpen())->toBeFalse()
            ->and($incident->fresh()?->resolved_at?->equalTo($raisedAt->addMinutes(64)))->toBeTrue()
            ->and(slackPosts())->toHaveCount(3)
            ->and(slackPosts()[2])->toContain('Cleared: Scheduler stopped')->toContain('The scheduler is running again')->toContain('It was open for 1 h 4 min')
            ->and(alertMails($this->tech))->toBe(['raised:scheduler_stopped', 'reminder:scheduler_stopped', 'cleared:scheduler_stopped'])
            ->and(DB::table('activity_log')->where('log_name', OperationsAudit::LOG)->orderBy('id')->pluck('event')->all())->toBe(['alert.raised', 'alert.cleared']);

        $this->getJson('/up/scheduler/'.$token)->assertOk();
    })->with(['the default 5 minutes' => 5, 'a window of 12 minutes' => 12]);

    it('raises it from any web request, not only the uptime link', function () {
        $start = CarbonImmutable::parse('2026-11-10 09:01:00', 'UTC');
        $this->travelTo($start);
        app(Heartbeats::class)->beat(Heartbeats::SCHEDULER);

        $this->travelTo($start->addMinutes(5));
        $this->get('/up')->assertOk();
        expect(OperationsAlert::query()->count())->toBe(0);

        $this->travelTo($start->addMinutes(6));
        $this->get('/up')->assertOk();

        expect(OperationsAlert::query()->sole()->raised_by)->toBe('web')
            ->and(alertMails($this->tech))->toBe(['raised:scheduler_stopped']);
    });

    it('needs no web traffic to have run before: a check at any later minute raises it at once', function () {
        $start = CarbonImmutable::parse('2026-11-10 09:01:00', 'UTC');
        $this->travelTo($start);
        app(Heartbeats::class)->beat(Heartbeats::SCHEDULER);

        $this->travelTo($start->addHours(3));
        app(Watchdog::class)->afterRequest();

        expect(OperationsAlert::query()->open()->sole()->summary)->toContain('has not run since 10 Nov 2026, 10:01 (Lagos) (more than 5 minutes)');
    });
});

it('never judges a scheduler that has not run yet: a new installation is the installer\'s business', function () {
    $token = uptimeToken();
    $this->travelTo(CarbonImmutable::parse('2026-11-10 09:01:00', 'UTC'));

    $this->getJson('/up/scheduler/'.$token)->assertStatus(503)->assertJson(['scheduler' => 'stopped', 'last_run_at' => null]);
    app(AlertMonitor::class)->run('scheduler');

    expect(OperationsAlert::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

it('reads one heartbeat per request and checks at most once a minute while the scheduler is stopped', function () {
    $start = CarbonImmutable::parse('2026-11-10 09:01:00', 'UTC');
    $this->travelTo($start);
    app(Heartbeats::class)->beat(Heartbeats::SCHEDULER);
    $this->travelTo($start->addMinutes(2));

    DB::enableQueryLog();
    app(Watchdog::class)->afterRequest();
    $fresh = DB::getQueryLog();
    DB::flushQueryLog();

    // One cache read: the heartbeat (fresh, so nothing else).
    expect($fresh)->toHaveCount(1);

    $this->travelTo($start->addMinutes(7));
    app(Watchdog::class)->afterRequest();
    OperationsAlert::query()->open()->sole()->forceFill(['open_key' => null, 'resolved_at' => now()])->save();

    // Within the same minute another request does not check again.
    $this->travelTo($start->addMinutes(7)->addSeconds(30));
    app(Watchdog::class)->afterRequest();
    expect(OperationsAlert::query()->open()->count())->toBe(0);

    $this->travelTo($start->addMinutes(8));
    app(Watchdog::class)->afterRequest();
    expect(OperationsAlert::query()->open()->count())->toBe(1);
});

it('keeps the uptime link private: no link made, or a wrong token, is a plain 404', function () {
    $this->getJson('/up/scheduler/'.str_repeat('x', 40))->assertNotFound();

    uptimeToken();
    $this->getJson('/up/scheduler/'.str_repeat('x', 40))->assertNotFound();
    $this->getJson('/up/scheduler/short')->assertNotFound();
});

it('sends nothing while alerts are switched off, and the dashboard still shows the problem', function () {
    app(SettingsStore::class)->set('operations.alerts_enabled', false);
    $this->travelTo(CarbonImmutable::parse('2026-11-10 09:01:00', 'UTC'));
    app(Heartbeats::class)->beat(Heartbeats::SCHEDULER);
    $this->travelTo(now()->addMinutes(30));

    app(Watchdog::class)->afterRequest();

    expect(OperationsAlert::query()->count())->toBe(0)->and(slackPosts())->toBe([]);
    Notification::assertNothingSent();

    $this->actingAs($this->tech, 'staff')->get('/'.config('app.admin_path').'/operations')->assertOk();
    Livewire::test(SchedulerWidget::class)->assertSeeHtml('<span data-figure="scheduler.state">Stopped</span>');
});

it('calls Slack only in production; elsewhere the message goes to the log', function () {
    app()->detectEnvironment(fn (): string => 'staging');
    Log::spy();
    $this->travelTo(CarbonImmutable::parse('2026-11-10 09:01:00', 'UTC'));
    app(Heartbeats::class)->beat(Heartbeats::SCHEDULER);
    $this->travelTo(now()->addMinutes(10));

    app(Watchdog::class)->afterRequest();

    Http::assertNothingSent();
    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'Slack is called only in production') && str_contains((string) ($context['text'] ?? ''), 'Scheduler stopped'));
    expect(alertMails($this->tech))->toBe(['raised:scheduler_stopped']);
});

it('still emails when Slack refuses, and never logs the webhook URL', function () {
    $this->slackStatus = 404;
    Log::spy();
    $this->travelTo(CarbonImmutable::parse('2026-11-10 09:01:00', 'UTC'));
    app(Heartbeats::class)->beat(Heartbeats::SCHEDULER);
    $this->travelTo(now()->addMinutes(10));

    app(Watchdog::class)->afterRequest();

    expect(alertMails($this->tech))->toBe(['raised:scheduler_stopped']);
    Log::shouldHaveReceived('error')->withArgs(fn (string $message): bool => $message === 'Slack refused the operations alert (HTTP 404).');
    Log::shouldNotHaveReceived('error', [Mockery::on(fn ($message): bool => str_contains((string) $message, 'hooks.slack.com'))]);
});

it('emails the staff chosen in the settings who may still see the dashboard, never deactivated staff', function () {
    $ops = staffNamed('Musa', ['operations-manager']);
    $gone = staffNamed('Gone', ['technical-admin']);
    $gone->forceFill(['status' => StaffStatus::Deactivated])->save();
    app(SettingsStore::class)->set('operations.alert_staff_ids', [$ops->id, $this->analyst->id, $gone->id]);

    $recipients = app(AlertNotifier::class)->recipients(AlertSettings::current());

    expect($recipients->pluck('name')->all())->toBe(['Musa']);

    app(SettingsStore::class)->set('operations.alert_staff_ids', []);

    expect(app(AlertNotifier::class)->recipients(AlertSettings::current())->pluck('name')->sort()->values()->all())->toBe(['Ada', 'Tobi']);
});

it('keeps an incident open when its check fails, rather than clearing it by mistake', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 09:01:00', 'UTC'));
    app(Heartbeats::class)->beat(Heartbeats::SCHEDULER);
    $this->travelTo(now()->addMinutes(10));
    app(AlertMonitor::class)->run('web', [AlertRule::SchedulerStopped]);

    $broken = new class implements AlertCheck
    {
        public function rule(): AlertRule
        {
            return AlertRule::SchedulerStopped;
        }

        public function failing(CarbonImmutable $now, AlertSettings $settings): array
        {
            throw new RuntimeException('cache down');
        }

        public function cleared(OperationsAlert $incident, CarbonImmutable $now): string
        {
            return 'never';
        }
    };

    (new AlertMonitor([$broken], app(AlertNotifier::class), app(OperationsAudit::class), app(Cache::class)))->run('scheduler');

    expect(OperationsAlert::query()->open()->count())->toBe(1)->and(slackPosts())->toHaveCount(1);
});

describe('the other rules', function () {
    beforeEach(function () {
        $this->travelTo(CarbonImmutable::parse('2026-11-10 09:01:00', 'UTC'));
        // The scheduler is alive: the checks run in its minute.
        app(Heartbeats::class)->beat(Heartbeats::SCHEDULER);
    });

    /** @return list<string> "rule|subject" of the open incidents */
    function openAlerts(): array
    {
        return OperationsAlert::query()->open()->orderBy('id')->pluck('open_key')->all();
    }

    it('raises a queue that waits longer than the threshold, once, and clears it when the queue moves', function () {
        config(['queue.default' => 'database']);
        $now = now()->getTimestamp();
        $job = fn (string $queue, int $availableAt, ?int $reservedAt = null): int => DB::table('jobs')->insertGetId(['queue' => $queue, 'payload' => '{}', 'attempts' => 0, 'reserved_at' => $reservedAt, 'available_at' => $availableAt, 'created_at' => $availableAt]);
        $old = $job('notifications', $now - 6 * 60);
        $job('notifications', $now - 60);
        $job('default', $now - 5 * 60);                 // exactly 5 minutes: not over
        $job('billing', $now - 30 * 60, $now - 30 * 60); // running, not waiting
        $job('pdf', $now + 3600);                       // delayed

        app(AlertMonitor::class)->run('scheduler');
        app(AlertMonitor::class)->run('scheduler');

        expect(openAlerts())->toBe(['queue_wait|notifications'])
            ->and(OperationsAlert::query()->sole()->summary)->toBe('2 jobs are waiting on the notifications queue; the oldest for more than 5 minutes.')
            ->and(slackPosts())->toHaveCount(1);

        DB::table('jobs')->where('id', $old)->delete();
        app(AlertMonitor::class)->run('scheduler');

        expect(openAlerts())->toBe([])
            ->and(slackPosts()[1])->toContain('Cleared: Queue waiting too long')->toContain('The notifications queue is moving again.');
    });

    it('raises Paystack webhook failures above the threshold for the platform\'s account and each agency\'s, from 3 deliveries an hour', function () {
        $agency = Organization::factory()->create(['name' => 'Okafor Homes']);
        $quiet = Organization::factory()->create();
        $delivery = fn (?int $organization, bool $valid, ?string $error, int $minutesAgo) => DB::table('webhook_log')->insert([
            'organization_id' => $organization, 'provider' => 'paystack', 'event' => 'charge.success', 'reference' => 'ref', 'payload' => '{}',
            'signature_valid' => $valid, 'processing_error' => $error, 'processed_at' => $error === null && $valid ? now() : null,
            'received_at' => now()->subMinutes($minutesAgo), 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Platform: 1 failure in 20 (5 %): not above 5 %.
        for ($i = 0; $i < 19; $i++) {
            $delivery(null, true, null, 10);
        }
        $delivery(null, false, null, 10);
        // Agency: 1 of 3 failed (33 %), plus older failures outside the hour.
        $delivery($agency->id, true, null, 5);
        $delivery($agency->id, true, null, 5);
        $delivery($agency->id, true, 'Paystack could not be reached', 5);
        $delivery($agency->id, false, null, 90);
        // Another agency: 2 of 2 failed, but under 3 deliveries.
        $delivery($quiet->id, false, null, 5);
        $delivery($quiet->id, false, null, 5);

        app(AlertMonitor::class)->run('scheduler');

        expect(openAlerts())->toBe(['webhook_failures|org:'.$agency->id])
            ->and(slackPosts()[0])->toContain('1 of 3 Paystack webhook deliveries for organization #'.$agency->id.' (its own account) failed in the last hour (33%)')
            ->and(slackPosts()[0])->not->toContain('Okafor');

        $delivery(null, false, null, 1);
        app(AlertMonitor::class)->run('scheduler');

        expect(openAlerts())->toContain('webhook_failures|platform');
    });

    it('raises a failed backup until a later backup to that destination succeeds, and a failed verify', function () {
        $r2 = BackupDestination::query()->create(['name' => 'R2', 'driver' => 's3', 'config' => ['bucket' => 'gp'], 'applies_to' => 'both']);
        $off = BackupDestination::query()->create(['name' => 'Old', 'driver' => 's3', 'config' => ['bucket' => 'old'], 'applies_to' => 'db', 'active' => false]);
        $run = fn (BackupDestination $to, string $type, string $status) => BackupRun::query()->create(['type' => $type, 'backup_destination_id' => $to->id, 'status' => $status, 'started_at' => now(), 'finished_at' => now(), 'triggered_by' => 'schedule', 'notes' => $status === 'failed' ? 'Bucket refused' : null]);
        $run($r2, 'db', 'success');
        $run($r2, 'db', 'failed');
        $run($r2, 'files', 'success');
        $run($off, 'db', 'failed'); // switched off: not judged
        $verify = new BackupOperation;
        $verify->forceFill(['kind' => OperationKind::Verify, 'status' => OperationStatus::Failed, 'started_at' => now(), 'finished_at' => now(), 'result' => 'Table missing'])->save();

        app(AlertMonitor::class)->run('scheduler');

        expect(openAlerts())->toBe(['backup_failed|db:'.$r2->id, 'backup_failed|verify'])
            ->and(slackPosts()[0])->toContain('Database backup to R2 failed at 10 Nov 2026, 10:01 (Lagos)');

        $this->travel(1)->hours();
        app(Heartbeats::class)->beat(Heartbeats::SCHEDULER);
        $run($r2, 'db', 'success');
        app(AlertMonitor::class)->run('scheduler');

        expect(openAlerts())->toBe(['backup_failed|verify']);
    });

    it('reminds once in a quarter\'s last month when no drill was recorded, and closes when one is', function () {
        $this->travelTo(CarbonImmutable::parse('2026-12-01 08:00:00', 'UTC'));
        app(Heartbeats::class)->beat(Heartbeats::SCHEDULER);
        $old = new BackupOperation;
        $old->forceFill(['kind' => OperationKind::Drill, 'status' => OperationStatus::Succeeded, 'drill_date' => '2026-09-20', 'reason' => 'Q3 drill went fine'])->save();

        app(AlertMonitor::class)->run('scheduler');
        expect(openAlerts())->toBe(['backup_drill|2026-Q4'])
            ->and(slackPosts()[0])->toContain('No restore drill has been recorded for this quarter (2026-Q4)')->toContain('by 31 Dec 2026');

        // Never repeated, however long it stays open.
        for ($day = 1; $day <= 5; $day++) {
            $this->travel(1)->days();
            app(Heartbeats::class)->beat(Heartbeats::SCHEDULER);
            app(AlertMonitor::class)->run('scheduler');
        }
        expect(slackPosts())->toHaveCount(1);

        $drill = new BackupOperation;
        $drill->forceFill(['kind' => OperationKind::Drill, 'status' => OperationStatus::Succeeded, 'drill_date' => '2026-12-05', 'reason' => 'Restored to staging, all good'])->save();
        app(AlertMonitor::class)->run('scheduler');

        expect(openAlerts())->toBe([])
            ->and(slackPosts()[1])->toContain('A restore drill was recorded for 2026-Q4.');

        // Not in the quarter's first two months.
        $this->travelTo(CarbonImmutable::parse('2027-02-10 08:00:00', 'UTC'));
        app(Heartbeats::class)->beat(Heartbeats::SCHEDULER);
        app(AlertMonitor::class)->run('scheduler');
        expect(openAlerts())->toBe([]);
    });

    it('raises a messaging provider judged failing, from 10 attempts in 24 hours', function () {
        $organization = Organization::factory()->create();
        $message = fn (string $provider, string $status, int $n) => collect(range(1, $n))->each(fn (int $i) => DB::table('notification_deliveries')->insert([
            'organization_id' => $organization->id, 'notification_type' => 'reminder.rent_due', 'channel' => 'sms', 'recipient_contact' => '+2348030000000',
            'body' => 'x', 'status' => $status, 'provider' => $provider, 'attempts' => 1, 'dedupe_key' => "{$provider}:{$status}:{$i}",
            'queued_at' => now()->subHour(), 'created_at' => now(), 'updated_at' => now(),
        ]));
        $message('gotext', 'delivered', 7);
        $message('gotext', 'failed', 3);   // 30 % of 10: failing
        $message('log', 'failed', 5);      // 100 %, but only 5 attempts

        app(AlertMonitor::class)->run('scheduler');

        expect(openAlerts())->toBe(['messaging_provider|sms:gotext'])
            ->and(slackPosts()[0])->toContain('SMS through GoText: 3 of 10 messages failed or were not delivered in the last 24 hours (30.0 %).');
    });

    it('raises an error spike from the errors reported in 15 minutes', function () {
        $counter = app(ErrorCounter::class);

        for ($i = 0; $i < 24; $i++) {
            $counter->record(CarbonImmutable::now()->subMinutes($i % 14));
        }
        $counter->record(CarbonImmutable::now()->subMinutes(20)); // outside the 15 minutes

        app(AlertMonitor::class)->run('scheduler');
        expect(openAlerts())->toBe([]);

        report(new RuntimeException('Something broke'));
        app(AlertMonitor::class)->run('scheduler');

        expect(openAlerts())->toBe(['error_spike|'])
            ->and(slackPosts()[0])->toContain('25 errors were reported in the last 15 minutes (the alert is set at 25)');
    });

    it('raises a scheduled task that failed or missed its run, and not before it was first seen', function () {
        $schedule = new Schedule('Africa/Lagos');
        $daily = $schedule->call(fn () => null)->name('nightly-thing')->dailyAt('01:30');
        $minute = $schedule->call(fn () => null)->name('minute-thing')->everyMinute();
        $tasks = app(ScheduledTasks::class);
        $tasks->capture($schedule, CarbonImmutable::now());

        // Seen for the first time at 09:01: the 01:30 run before that is not judged.
        $tasks->record($minute, 'failed', new RuntimeException('Boom with +2348031234567'));
        app(AlertMonitor::class)->run('scheduler');

        expect(openAlerts())->toBe(['scheduled_task|minute-thing'])
            ->and(slackPosts()[0])->toContain('The scheduled task minute-thing failed at 10 Nov 2026, 10:01 (Lagos) (RuntimeException)')
            ->and(slackPosts()[0])->not->toContain('2348031234567');

        $tasks->record($minute, 'ok');
        $this->travelTo(CarbonImmutable::parse('2026-11-11 00:40:00', 'UTC')); // 01:40 Lagos: the 01:30 run is due, with 15 minutes' grace
        app(Heartbeats::class)->beat(Heartbeats::SCHEDULER);
        $tasks->record($minute, 'ok');
        app(AlertMonitor::class)->run('scheduler');
        expect(openAlerts())->toBe([]);

        $this->travelTo(CarbonImmutable::parse('2026-11-11 00:46:00', 'UTC'));
        app(Heartbeats::class)->beat(Heartbeats::SCHEDULER);
        $tasks->record($minute, 'ok');
        app(AlertMonitor::class)->run('scheduler');
        expect(openAlerts())->toBe(['scheduled_task|nightly-thing'])
            ->and(OperationsAlert::query()->open()->sole()->summary)->toBe('The scheduled task nightly-thing was due at 11 Nov 2026, 01:30 (Lagos) and has not run since.');

        $tasks->record($daily, 'ok');
        app(AlertMonitor::class)->run('scheduler');
        expect(openAlerts())->toBe([]);
    });

    it('does not judge scheduled tasks while the scheduler itself is stopped: that is one alert', function () {
        $schedule = new Schedule;
        $schedule->call(fn () => null)->name('minute-thing')->everyMinute();
        app(ScheduledTasks::class)->capture($schedule, CarbonImmutable::now());

        $this->travel(30)->minutes();
        app(AlertMonitor::class)->run('web');

        expect(openAlerts())->toBe(['scheduler_stopped|']);
    });
});

it('leaves the notice kinds and their words to one place', function () {
    expect(NoticeKind::Raised->prefix())->toBe('Alert')
        ->and(NoticeKind::Reminder->prefix())->toBe('Still failing')
        ->and(NoticeKind::Cleared->prefix())->toBe('Cleared');
});
