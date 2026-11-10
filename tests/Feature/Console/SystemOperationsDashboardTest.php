<?php

declare(strict_types=1);

use App\Backup\BackupDestination;
use App\Backup\BackupRun;
use App\Backup\Operations\BackupOperation;
use App\Backup\Operations\OperationKind;
use App\Backup\Operations\OperationStatus;
use App\Hosting\Heartbeats;
use App\Models\ImpersonationSession;
use App\Models\Organization;
use App\Models\PlatformStaff;
use App\Models\User;
use App\Platform\Filament\Pages\Settings\OperationsAlertSettings;
use App\Platform\Filament\Pages\SystemOperations;
use App\Platform\Filament\Widgets\Operations\AlertsWidget;
use App\Platform\Filament\Widgets\Operations\AppStatusWidget;
use App\Platform\Filament\Widgets\Operations\BackupsWidget;
use App\Platform\Filament\Widgets\Operations\QueuesWidget;
use App\Platform\Filament\Widgets\Operations\SchedulerWidget;
use App\Platform\Filament\Widgets\Operations\SecurityWidget;
use App\Platform\Filament\Widgets\Operations\StorageWidget;
use App\Platform\Filament\Widgets\Operations\WebhooksWidget;
use App\Platform\Operations\Alerts\AlertRule;
use App\Platform\Operations\Alerts\OperationsAlert;
use App\Platform\Operations\OperationsAudit;
use App\Platform\Operations\Queues\FailedJobs;
use App\Platform\Operations\Scheduler\ScheduledTasks;
use App\Settings\SettingsStore;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

/*
 * P08-03: the System Operations dashboard's areas (PLATFORM_CONSOLE.md § 2):
 * alerts, app, queues, scheduler, webhooks, backups, storage and security,
 * read back from the rendered widgets against a fixture; who sees which
 * area; Retry / Forget on failed jobs; Settings → Operations alerts.
 */

const OPS_NOW = '2026-11-10 09:01:00';

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('console'));
    seedStaffRoles();
    $this->travelTo(CarbonImmutable::parse(OPS_NOW, 'UTC'));
    $this->console = '/'.config('app.admin_path');
    $this->tech = staffNamed('Tobi', ['technical-admin']);
});

/** @return array<string, string> data-figure => text, from a rendered widget */
function opsFigures(string $html): array
{
    preg_match_all('/data-figure="([^"]+)"[^>]*>(.*?)<\//s', $html, $matches, PREG_SET_ORDER);
    $figures = [];

    foreach ($matches as [, $key, $value]) {
        $figures[$key] = trim(html_entity_decode(strip_tags($value)));
    }

    return $figures;
}

function opsCode(PlatformStaff $staff): string
{
    return app(Google2FA::class)->getCurrentOtp((string) $staff->two_factor_secret);
}

it('shows each queue\'s depth and wait and the failed jobs, payloads never shown and numbers masked', function () {
    config(['queue.default' => 'database']);
    $now = now()->getTimestamp();
    $job = fn (string $queue, int $availableAt, ?int $reservedAt = null) => DB::table('jobs')->insert(['queue' => $queue, 'payload' => '{"secret":"PAYLOAD-SECRET"}', 'attempts' => 0, 'reserved_at' => $reservedAt, 'available_at' => $availableAt, 'created_at' => $availableAt]);
    $job('notifications', $now - 7 * 60);
    $job('notifications', $now - 30);
    $job('notifications', $now + 600);
    $job('billing', $now - 120, $now - 60);
    $job('reports-extra', $now - 90);
    DB::table('failed_jobs')->insert([
        ['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'notifications', 'payload' => json_encode(['displayName' => 'App\\Sms\\SendSms', 'data' => 'PAYLOAD-SECRET']), 'exception' => "RuntimeException: GoText refused +2348031234567 for ada@example.com\n#0 /app/x.php", 'failed_at' => now()->subHours(2)],
        ['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'pdf', 'payload' => '{}', 'exception' => 'Old failure', 'failed_at' => now()->subDays(3)],
    ]);
    $this->actingAs($this->tech, 'staff');

    $html = Livewire::test(QueuesWidget::class)->html();
    $figures = opsFigures($html);

    expect($figures)->toMatchArray([
        'queue.notifications.waiting' => '2', 'queue.notifications.wait' => '7 min', 'queue.notifications.delayed' => '1', 'queue.notifications.running' => '0',
        'queue.billing.waiting' => '0', 'queue.billing.wait' => '—', 'queue.billing.running' => '1',
        'queue.default.waiting' => '0', 'queue.imports.waiting' => '0',
        'queue.reports-extra.waiting' => '1', 'queue.reports-extra.wait' => '1 min',
        'failed.day' => '1', 'failed.all' => '2',
    ])
        ->and($html)->toContain('SendSms')->toContain('+234 ••• 4567')->toContain('[email]')
        ->not->toContain('PAYLOAD-SECRET')->not->toContain('ada@example.com')->not->toContain('2348031234567')
        ->toContain('Retry');
});

it('retries and forgets failed jobs for queues.manage only, each audited', function () {
    $uuid = (string) Str::uuid();
    $other = (string) Str::uuid();
    $payload = json_encode(['uuid' => $uuid, 'displayName' => 'App\\Sms\\SendSms', 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'maxTries' => null, 'attempts' => 3, 'data' => ['commandName' => 'App\\Sms\\SendSms']]);
    DB::table('failed_jobs')->insert([
        ['uuid' => $uuid, 'connection' => 'database', 'queue' => 'notifications', 'payload' => $payload, 'exception' => 'Boom', 'failed_at' => now()],
        ['uuid' => $other, 'connection' => 'database', 'queue' => 'pdf', 'payload' => $payload, 'exception' => 'Boom', 'failed_at' => now()],
    ]);

    // Operations managers see the queues but may not touch them.
    $this->actingAs(staffNamed('Musa', ['operations-manager']), 'staff');
    Livewire::test(QueuesWidget::class)->assertDontSee('Retry')->call('retryFailedJob', $uuid)->assertForbidden();
    expect(fn () => app(FailedJobs::class)->forget(PlatformStaff::query()->where('name', 'Musa')->firstOrFail(), $uuid))->toThrow(AuthorizationException::class);

    $this->actingAs($this->tech, 'staff');
    Livewire::test(QueuesWidget::class)->call('retryFailedJob', $uuid)->assertNotified('Put back on its queue');

    expect(DB::table('failed_jobs')->where('uuid', $uuid)->exists())->toBeFalse()
        ->and(DB::table('jobs')->where('queue', 'notifications')->count())->toBe(1);

    Livewire::test(QueuesWidget::class)->call('forgetFailedJob', $other)->assertNotified('Failed job forgotten');
    Livewire::test(QueuesWidget::class)->call('forgetFailedJob', $other)->assertNotified('That failed job is no longer listed.');

    expect(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('activity_log')->where('log_name', OperationsAudit::LOG)->orderBy('id')->get(['event', 'causer_id', 'properties'])->map(fn (object $row): array => [$row->event, (int) $row->causer_id, json_decode((string) $row->properties, true)['queue'] ?? null])->all())
        ->toBe([['failed_job.retried', $this->tech->id, 'notifications'], ['failed_job.forgotten', $this->tech->id, 'pdf']]);
});

it('shows the scheduler and queue tick heartbeats, every task\'s last result and next run, and the cron line to system.status only', function () {
    app(Heartbeats::class)->beat(Heartbeats::SCHEDULER);
    app(Heartbeats::class)->beat(Heartbeats::QUEUE_TICK, ['stopped_by' => 'drained']);
    $schedule = new Schedule;
    $ok = $schedule->call(fn () => null)->name('backup-db')->dailyAt('01:30');
    $bad = $schedule->call(fn () => null)->name('audit-chain-verify')->dailyAt('02:30');
    $schedule->call(fn () => null)->name('never-yet')->hourly();
    $tasks = app(ScheduledTasks::class);
    $tasks->capture($schedule, CarbonImmutable::now()->subDays(2));
    $tasks->capture($schedule, CarbonImmutable::now());
    $tasks->record($ok, 'ok', seconds: 4.2);
    $tasks->record($bad, 'failed', new RuntimeException('Chain broken at +2348031234567'));

    $this->actingAs($this->tech, 'staff');
    $html = Livewire::test(SchedulerWidget::class)->html();
    $figures = opsFigures($html);

    expect($figures['scheduler.state'])->toBe('Running')
        ->and($figures['scheduler.last'])->toBe('10 Nov 2026, 10:01')
        ->and($figures['tick.last'])->toBe('10 Nov 2026, 10:01')
        ->and($figures['task.backup-db'])->toBe('OK')
        ->and($figures['task.audit-chain-verify'])->toBe('Failed (RuntimeException)')
        ->and($figures['task.never-yet'])->toBe('Not run since the list was taken')
        ->and($figures['scheduler.cron'])->toContain('schedule:run')
        ->and($html)->not->toContain('2348031234567');

    $this->actingAs(staffNamed('Musa', ['operations-manager']), 'staff');
    expect(opsFigures(Livewire::test(SchedulerWidget::class)->html()))->not->toHaveKey('scheduler.cron');

    $this->travel(10)->minutes();
    $this->actingAs($this->tech, 'staff');
    expect(opsFigures(Livewire::test(SchedulerWidget::class)->html())['scheduler.state'])->toBe('Stopped');
});

it('shows Paystack webhooks for the platform\'s account and each agency\'s, failures first', function () {
    $okafor = Organization::factory()->create(['name' => 'Okafor Homes']);
    $adeyemi = Organization::factory()->create(['name' => 'Adeyemi Realty']);
    $silent = Organization::factory()->create(['name' => 'Silent Estates']);
    foreach ([$okafor, $adeyemi, $silent] as $i => $organization) {
        DB::table('organization_paystack_connections')->insert(['organization_id' => $organization->id, 'webhook_token' => str_repeat((string) $i, 48), 'mode' => 'live', 'status' => $i === 2 ? 'failing' : 'active', 'created_at' => now(), 'updated_at' => now()]);
    }
    $delivery = fn (?int $organization, bool $valid, ?string $error, bool $processed, int $hoursAgo) => DB::table('webhook_log')->insert([
        'organization_id' => $organization, 'provider' => 'paystack', 'event' => 'charge.success', 'reference' => 'r', 'payload' => 'BODY-SECRET',
        'signature_valid' => $valid, 'processing_error' => $error, 'processed_at' => $processed ? now() : null,
        'received_at' => now()->subHours($hoursAgo), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $delivery(null, true, null, true, 1);
    $delivery(null, true, null, true, 2);
    $delivery(null, false, null, false, 3);
    $delivery(null, true, null, false, 1);   // awaiting
    $delivery(null, true, null, true, 30);   // outside 24 h, but the last event is newer
    $delivery($adeyemi->id, true, 'Paystack could not be reached', false, 2);
    $delivery($adeyemi->id, true, null, true, 2);
    $delivery($okafor->id, true, null, true, 5);

    $this->actingAs($this->tech, 'staff');
    $html = Livewire::test(WebhooksWidget::class)->html();
    $figures = opsFigures($html);

    expect($figures)->toMatchArray([
        'webhooks.platform.received' => '4', 'webhooks.platform.failed' => '1', 'webhooks.platform.rate' => '25%', 'webhooks.platform.awaiting' => '1', 'webhooks.platform.last' => '10 Nov 2026, 09:01',
        "webhooks.org{$adeyemi->id}.received" => '2', "webhooks.org{$adeyemi->id}.failed" => '1', "webhooks.org{$adeyemi->id}.rate" => '50%',
        "webhooks.org{$okafor->id}.received" => '1', "webhooks.org{$okafor->id}.rate" => '0%',
        "webhooks.org{$silent->id}.received" => '0', "webhooks.org{$silent->id}.rate" => '—', "webhooks.org{$silent->id}.last" => 'never',
    ])
        ->and(strpos($html, 'Adeyemi Realty'))->toBeLessThan(strpos($html, 'Okafor Homes'))
        ->and($html)->toContain('connection: failing')->toContain('3 agencies')->not->toContain('BODY-SECRET');
});

it('shows the last backups per destination, the next runs, the last verify and drill', function () {
    $r2 = BackupDestination::query()->create(['name' => 'R2 bucket', 'driver' => 's3', 'config' => ['bucket' => 'gp'], 'applies_to' => 'both']);
    $drive = BackupDestination::query()->create(['name' => 'Shared Drive', 'driver' => 'google_drive', 'config' => ['shared_drive_id' => 'x'], 'applies_to' => 'db']);
    $run = fn (BackupDestination $to, string $type, string $status, int $size, int $hoursAgo) => BackupRun::query()->create(['type' => $type, 'backup_destination_id' => $to->id, 'status' => $status, 'size_bytes' => $size, 'started_at' => now()->subHours($hoursAgo), 'finished_at' => now()->subHours($hoursAgo), 'triggered_by' => 'schedule']);
    $run($r2, 'db', 'success', 5 * 1024 * 1024, 8);
    $run($drive, 'db', 'failed', 0, 8);
    $run($r2, 'files', 'success', 2048, 7);
    $verify = new BackupOperation;
    $verify->forceFill(['kind' => OperationKind::Verify, 'status' => OperationStatus::Succeeded, 'started_at' => now()->subDays(9), 'finished_at' => now()->subDays(9)])->save();
    $drill = new BackupOperation;
    $drill->forceFill(['kind' => OperationKind::Drill, 'status' => OperationStatus::Succeeded, 'drill_date' => '2026-10-15', 'reason' => 'Restored to staging, checked'])->save();
    $schedule = new Schedule;
    $schedule->call(fn () => null)->name('backup-db')->dailyAt('01:30');
    $schedule->call(fn () => null)->name('backup-files')->dailyAt('02:30');
    app(ScheduledTasks::class)->capture($schedule, CarbonImmutable::now());

    $this->actingAs($this->tech, 'staff');
    $figures = opsFigures(Livewire::test(BackupsWidget::class)->html());

    expect($figures)->toMatchArray([
        'backup.db.last' => '10 Nov 2026, 02:01', 'backup.db.next' => '11 Nov 2026, 02:30',
        'backup.files.last' => '10 Nov 2026, 03:01', 'backup.files.next' => '11 Nov 2026, 03:30',
        'backup.verify' => '1 Nov 2026, 10:01 (passed)', 'backup.drill' => '15 Oct 2026 (passed)',
    ]);

    $html = Livewire::test(BackupsWidget::class)->html();
    expect($html)->toContain('5.0 MB')->toContain('Shared Drive')->toContain('Failed');
});

it('counts failed sign-ins, lockouts, open support sessions and staff sign-ins of the last 24 hours', function () {
    $log = fn (string $type, ?int $id, string $event, int $hoursAgo, bool $new = false) => DB::table('authentication_logs')->insert(['authenticatable_type' => $type, 'authenticatable_id' => $id, 'event' => $event, 'ip' => '102.89.1.1', 'is_new_device' => $new, 'location' => 'NG', 'created_at' => now()->subHours($hoursAgo)]);
    $log('user', null, 'login_failed', 1);
    $log('user', null, '2fa_failed', 2);
    $log('user', null, 'login_failed', 30);
    $log('platform_staff', $this->tech->id, 'login_failed', 3);
    $log('user', null, 'lockout', 4);
    $log('platform_staff', $this->tech->id, 'login_success', 1, true);
    $log('platform_staff', $this->tech->id, 'login_success', 40);
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    ImpersonationSession::query()->forceCreate(['staff_id' => $this->tech->id, 'user_id' => $user->id, 'organization_id' => $organization->id, 'reason' => 'Ticket 9', 'mode' => 'read_only', 'started_at' => now()->subMinutes(5), 'expires_at' => now()->addMinutes(25)]);
    ImpersonationSession::query()->forceCreate(['staff_id' => $this->tech->id, 'user_id' => $user->id, 'organization_id' => $organization->id, 'reason' => 'Ticket 8', 'mode' => 'read_only', 'started_at' => now()->subHour(), 'expires_at' => now()->subMinutes(30)]);

    $this->actingAs($this->tech, 'staff');
    $html = Livewire::test(SecurityWidget::class)->html();

    expect(opsFigures($html))->toMatchArray([
        'security.failed_customers' => '2', 'security.failed_staff' => '1', 'security.lockouts' => '1', 'security.sessions' => '1', 'security.staff_sign_ins' => '1',
    ])
        ->and($html)->toContain('Tobi')->toContain('New device')->not->toContain('102.89.1.1');
});

it('shows the app release, maintenance mode, migrations and errors reported, and storage', function () {
    report(new RuntimeException('one'));
    report(new RuntimeException('two'));

    $this->actingAs($this->tech, 'staff');
    $figures = opsFigures(Livewire::test(AppStatusWidget::class)->html());

    expect($figures)->toMatchArray(['app.version' => 'dev', 'app.maintenance' => 'Off', 'app.pending_migrations' => '0', 'app.errors_15m' => '2', 'app.errors_1h' => '2']);

    $organization = Organization::factory()->create();
    DB::table('media')->insert(['organization_id' => $organization->id, 'model_type' => 'x', 'model_id' => 1, 'uuid' => (string) Str::uuid(), 'collection_name' => 'photos', 'name' => 'a', 'file_name' => 'a.jpg', 'disk' => 'local', 'size' => 3 * 1024 * 1024, 'manipulations' => '[]', 'custom_properties' => '[]', 'generated_conversions' => '[]', 'responsive_images' => '[]']);
    $storage = Livewire::test(StorageWidget::class)->html();

    expect(opsFigures($storage)['storage.media.local'])->toBe('3.0 MB')
        ->and(opsFigures($storage)['storage.database'])->toMatch('/^\d+(\.\d)? (KB|MB|GB)$/')
        ->and($storage)->toContain('Largest tables')->toContain('from tomorrow');
});

it('lists open alerts and recently cleared ones, and where alerts go', function () {
    OperationsAlert::query()->create(['rule' => AlertRule::QueueWait, 'subject' => 'billing', 'open_key' => 'queue_wait|billing', 'summary' => '3 jobs are waiting on the billing queue', 'raised_by' => 'scheduler', 'started_at' => now()->subMinutes(20), 'last_notified_at' => now()->subMinutes(20), 'notifications' => 1]);
    OperationsAlert::query()->create(['rule' => AlertRule::BackupFailed, 'subject' => 'db:1', 'open_key' => null, 'summary' => 'x', 'raised_by' => 'scheduler', 'started_at' => now()->subDay(), 'resolved_at' => now()->subHours(20), 'notifications' => 1]);
    app(SettingsStore::class)->set('operations.alert_slack_webhook_url', 'https://hooks.slack.com/services/T1/B1/secretsecretsecret');

    $this->actingAs($this->tech, 'staff');
    $html = Livewire::test(AlertsWidget::class)->html();

    expect(opsFigures($html))->toMatchArray(['alerts.enabled' => 'on', 'alerts.slack' => 'set', 'alerts.recipients' => '1'])
        ->and($html)->toContain('data-alert="queue_wait|billing"')->toContain('3 jobs are waiting on the billing queue')
        ->toContain('Recently cleared (1)')->toContain('Change where alerts go')
        ->not->toContain('secretsecretsecret');

    $this->actingAs(staffNamed('Musa', ['operations-manager']), 'staff');
    expect(Livewire::test(AlertsWidget::class)->html())->not->toContain('Change where alerts go');
});

it('shows each area only to the staff it is for, and the page to anyone who sees one', function () {
    $areas = [AlertsWidget::class, AppStatusWidget::class, QueuesWidget::class, SchedulerWidget::class, WebhooksWidget::class, BackupsWidget::class, StorageWidget::class, SecurityWidget::class];
    $seen = [];

    foreach (['super-admin', 'technical-admin', 'operations-manager', 'support-agent', 'analyst', 'finance-admin', 'compliance-officer', 'content-manager'] as $role) {
        $this->flushSession();
        $this->actingAs(staffNamed(Str::studly($role), [$role]), 'staff');
        $seen[$role] = array_values(array_map(class_basename(...), array_filter($areas, fn (string $widget): bool => $widget::canView())));
        $this->get($this->console.'/operations')->assertStatus(SystemOperations::canAccess() ? 200 : 403);
    }

    $all = array_map(class_basename(...), $areas);
    expect($seen['super-admin'])->toBe($all)
        ->and($seen['technical-admin'])->toBe($all)
        ->and($seen['operations-manager'])->toBe($all)
        // The delivery log's readers keep the Messaging area only (P05-05).
        ->and($seen['support-agent'])->toBe([])
        ->and($seen['analyst'])->toBe([])
        ->and($seen['finance-admin'])->toBe([])
        ->and($seen['compliance-officer'])->toBe([])
        ->and($seen['content-manager'])->toBe([]);
});

describe('Settings → Operations alerts', function () {
    beforeEach(function () {
        $this->actingAs($this->tech, 'staff');
    });

    it('opens for technical-admin and super-admin only', function () {
        $this->get($this->console.'/settings/operations-alerts')->assertOk()->assertSee('Operations alerts');

        foreach (['operations-manager', 'support-agent', 'analyst'] as $role) {
            $this->flushSession();
            $this->actingAs(staffNamed(Str::studly($role), [$role]), 'staff')->get($this->console.'/settings/operations-alerts')->assertForbidden();
        }
    });

    it('saves the thresholds and the staff to email, audited by the settings store', function () {
        $boss = staffNamed('Ada', ['super-admin']);

        Livewire::test(OperationsAlertSettings::class)
            ->fillForm(['operations' => ['alert_scheduler_minutes' => 10, 'alert_renotify_minutes' => 120, 'alert_staff_ids' => [(string) $boss->id]]])
            ->callAction('save')
            ->assertNotified('Saved');

        $settings = app(SettingsStore::class);
        expect($settings->get('operations.alert_scheduler_minutes'))->toBe(10)
            ->and($settings->get('operations.alert_renotify_minutes'))->toBe(120)
            ->and($settings->get('operations.alert_staff_ids'))->toBe([$boss->id])
            ->and(DB::table('activity_log')->where('log_name', 'settings')->where('properties->key', 'operations.alert_scheduler_minutes')->exists())->toBeTrue();

        Livewire::test(OperationsAlertSettings::class)
            ->fillForm(['operations' => ['alert_scheduler_minutes' => 2]])
            ->callAction('save')
            ->assertHasFormErrors(['operations.alert_scheduler_minutes']);
    });

    it('keeps the Slack webhook encrypted, never shows it back, and asks for step-up to change it', function () {
        $url = 'https://hooks.slack.com/services/T0001/B0001/OLDSECRETvalue9876';
        app(SettingsStore::class)->set('operations.alert_slack_webhook_url', $url);

        $this->get($this->console.'/settings/operations-alerts')->assertOk()->assertSee('•••• 9876')->assertDontSee('OLDSECRET');
        expect((string) DB::table('settings')->where('key', 'operations.alert_slack_webhook_url')->value('value'))->not->toContain('hooks.slack.com');

        $new = 'https://hooks.slack.com/services/T0001/B0001/NEWSECRETvalue1234';
        $page = Livewire::test(OperationsAlertSettings::class)->fillForm(['new_slack_url' => $new]);
        $page->callAction('save', data: ['password' => 'wrong-password', 'code' => '000000'])->assertNotified('The password or code is not right.');
        expect(app(SettingsStore::class)->get('operations.alert_slack_webhook_url'))->toBe($url);

        $page->callAction('save', data: ['password' => 'x-long-password-1', 'code' => opsCode($this->tech)])->assertNotified('Saved');
        expect(app(SettingsStore::class)->get('operations.alert_slack_webhook_url'))->toBe($new)
            ->and(DB::table('activity_log')->where('log_name', 'settings')->where('properties->key', 'operations.alert_slack_webhook_url')->latest('id')->value('properties'))->not->toContain('NEWSECRET');

        // Only Slack's own host.
        Livewire::test(OperationsAlertSettings::class)->fillForm(['new_slack_url' => 'https://evil.example/hook/abcdefghijk'])->callAction('save')
            ->assertHasFormErrors(['new_slack_url']);

        Livewire::test(OperationsAlertSettings::class)->fillForm(['clear_slack_url' => true])->callAction('save')->assertNotified('Saved');
        expect(app(SettingsStore::class)->get('operations.alert_slack_webhook_url'))->toBeNull();
    });

    it('makes an uptime link shown once and kept as a hash, and removes it', function () {
        $token = 'Up'.str_repeat('x9', 19);
        Str::createRandomStringsUsing(fn (int $length): string => substr($token, 0, $length));

        Livewire::test(OperationsAlertSettings::class)
            ->callAction('makeUptimeLink', data: ['password' => 'x-long-password-1', 'code' => opsCode($this->tech)])
            ->assertNotified('Uptime link made');
        Str::createRandomStringsNormally();

        $hash = app(SettingsStore::class)->get('operations.uptime_token_hash');
        $match = [1 => $token];

        expect($hash)->toBe(hash('sha256', $token))
            ->and(DB::table('activity_log')->where('log_name', 'settings')->where('properties->key', 'operations.uptime_token_hash')->value('properties'))->not->toContain($hash);

        app(Heartbeats::class)->beat(Heartbeats::SCHEDULER);
        $this->getJson('/up/scheduler/'.$match[1])->assertOk()->assertJsonPath('scheduler', 'running');

        Livewire::test(OperationsAlertSettings::class)->callAction('removeUptimeLink');
        expect(app(SettingsStore::class)->get('operations.uptime_token_hash'))->toBeNull();
        $this->getJson('/up/scheduler/'.$match[1])->assertNotFound();
    });

    it('sends a test alert to Slack and the staff emailed, audited', function () {
        Notification::fake();
        Http::fake(['hooks.slack.com/*' => Http::response('ok')]);
        app()->detectEnvironment(fn (): string => 'production');
        app(SettingsStore::class)->set('operations.alert_slack_webhook_url', 'https://hooks.slack.com/services/T1/B1/testsecretvalue123');

        Livewire::test(OperationsAlertSettings::class)->callAction('sendTest')->assertNotified('Test sent. Slack took it; 1 staff emailed.');

        Http::assertSent(fn ($request): bool => str_contains((string) ($request->data()['text'] ?? ''), 'Test alert: operations alerts'));
        expect(DB::table('activity_log')->where('log_name', OperationsAudit::LOG)->where('event', 'alert.test_sent')->value('causer_id'))->toBe($this->tech->id);
    });

    it('says the Slack message went to the log outside production, and never calls Slack there', function () {
        Notification::fake();
        Http::fake();
        app()->detectEnvironment(fn (): string => 'staging');
        app(SettingsStore::class)->set('operations.alert_slack_webhook_url', 'https://hooks.slack.com/services/T1/B1/testsecretvalue123');

        Livewire::test(OperationsAlertSettings::class)->callAction('sendTest')
            ->assertNotified('Test sent. Slack is called only in production, so the Slack message went to the log; 1 staff emailed.');

        Http::assertNothingSent();
        expect(json_decode((string) DB::table('activity_log')->where('log_name', OperationsAudit::LOG)->where('event', 'alert.test_sent')->value('properties'), true))
            ->toMatchArray(['slack' => null, 'slack_logged' => true, 'emails' => 1]);
    });
});
