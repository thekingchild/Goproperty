<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Backup\BackupDestination;
use App\Backup\BackupRun;
use App\Backup\Operations\BackupOperation;
use App\Backup\Operations\BackupOperations;
use App\Backup\Operations\BackupRefused;
use App\Backup\Operations\Destinations;
use App\Backup\Operations\DownloadLinks;
use App\Backup\Operations\OperationKind;
use App\Backup\Operations\OperationRunner;
use App\Backup\Operations\OperationStatus;
use App\Models\ApprovalRequest;
use App\Models\PlatformStaff;
use App\Platform\Approvals\ApprovalAction;
use App\Platform\Approvals\ApprovalRefused;
use App\Platform\Approvals\ApprovalService;
use App\Platform\Approvals\ApprovalStatus;
use App\Platform\Console\StepUp;
use App\Platform\Filament\Resources\ApprovalRequests\Pages\ListApprovalRequests;
use App\Platform\Filament\Resources\BackupDestinations\Pages\ListBackupDestinations;
use App\Platform\Filament\Resources\BackupOperations\Pages\ListBackupOperations;
use App\Platform\Filament\Resources\Backups\Pages\ListBackups;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

/*
 * P06-10: the backups console (PLATFORM_CONSOLE.md § 4; defaults 102–117
 * of docs/plans/P06-subtasks.md). Operations → Backups (back up now,
 * download, verify, restore to staging, ask for a production restore,
 * record a drill), Backup activity and Backup destinations, through
 * App\Backup\Operations. The restores and the verify themselves replace
 * databases, so they are drilled in tests/Drill/BackupConsoleDrillTest.php.
 */

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('console'));
    seedStaffRoles();
    $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00', 'Africa/Lagos'));
    $this->console = '/'.config('app.admin_path');
    $this->tayo = staffNamed('Tayo', ['technical-admin']);
    $this->root = storage_path('framework/testing/backups-console-'.Str::random(8));
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

/** A folder destination (drills only) with a database backup zip in it. */
function folderBackup(string $root, string $name = '2026-11-09-01-30-00'): BackupRun
{
    File::ensureDirectoryExists($root.'/goproperty-db');
    File::put($root.'/goproperty-db/'.$name.'.zip', 'encrypted zip bytes');
    $destination = BackupDestination::query()->firstOrCreate(['name' => 'Drill folder'], ['driver' => 'local', 'config' => ['root' => $root], 'applies_to' => 'both']);

    return BackupRun::query()->create([
        'type' => 'db', 'filename' => 'goproperty-db/'.$name.'.zip', 'size_bytes' => 19, 'backup_destination_id' => $destination->id,
        'status' => 'success', 'started_at' => now()->subHour(), 'finished_at' => now()->subHour(), 'triggered_by' => 'schedule',
    ]);
}

/**
 * The staff member's current authenticator code. Fortify refuses a code
 * used once already, so each call stands for a new 30-second window.
 */
function backupStaffCode(PlatformStaff $staff): string
{
    $code = app(Google2FA::class)->getCurrentOtp((string) $staff->two_factor_secret);
    Cache::forget('fortify.2fa_codes.'.md5($code));

    return $code;
}

/** Confirms the staff member's password and code, as the restore form does. */
function stepUpAs(PlatformStaff $staff): void
{
    test()->actingAs($staff, 'staff');
    expect(app(StepUp::class)->confirm($staff, 'x-long-password-1', backupStaffCode($staff)))->toBeNull();
}

it('shows the backups, their activity and destinations to technical and super admins only', function () {
    folderBackup($this->root);

    $this->actingAs($this->tayo, 'staff');
    $this->get($this->console.'/backups')->assertOk()->assertSee('goproperty-db/2026-11-09-01-30-00.zip')->assertSee('Last database backup: 10 Nov 2026 09:00');
    $this->get($this->console.'/backup-activity')->assertOk();
    $this->get($this->console.'/backup-destinations')->assertOk()->assertSee('Drill folder');

    $this->flushSession();
    $this->actingAs(staffNamed('Funmi', ['finance-admin']), 'staff');

    foreach (['/backups', '/backup-activity', '/backup-destinations'] as $page) {
        $this->get($this->console.$page)->assertForbidden();
    }
});

it('queues a backup now and runs it at the next minute', function () {
    $r2 = BackupDestination::query()->create(['name' => 'R2', 'driver' => 's3', 'config' => ['key' => 'AKIA', 'secret' => 'secret-value', 'bucket' => 'gp', 'endpoint' => 'https://acct.r2.cloudflarestorage.com'], 'applies_to' => 'both']);
    Storage::fake($r2->diskName());
    $this->actingAs($this->tayo, 'staff');

    Livewire::test(ListBackups::class)->callTableAction('backUpDb')->assertNotified('Backup queued: it starts within a minute.');
    Livewire::test(ListBackups::class)->callTableAction('backUpDb')->assertNotified('A database backup is already queued or running.');

    $operation = BackupOperation::query()->sole();
    expect($operation->kind)->toBe(OperationKind::Backup)
        ->and($operation->status)->toBe(OperationStatus::Queued)
        ->and($operation->requested_by_staff_id)->toBe($this->tayo->id);

    expect(app(OperationRunner::class)->runDue())->toBe(1);

    $run = BackupRun::query()->sole();
    expect($operation->fresh()->status)->toBe(OperationStatus::Succeeded)
        ->and($operation->fresh()->result)->toContain('R2: saved goproperty-db/')
        ->and($run->triggered_by)->toBe('manual')
        ->and($run->triggered_by_staff_id)->toBe($this->tayo->id)
        ->and(AuditEntry::query()->where('log_name', 'backups')->pluck('event')->all())->toBe(['backup.run_requested', 'backup.backup_succeeded']);

    // Nothing left to run.
    expect(app(OperationRunner::class)->runDue())->toBe(0);
});

it('runs the queued requests every minute in the scheduler, without overlapping', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event) => $event->description === 'backup-operations');

    expect($event?->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('downloads a backup through a signed link that expires in 15 minutes, auditing each download', function () {
    $run = folderBackup($this->root);
    $this->actingAs($this->tayo, 'staff');
    $link = app(DownloadLinks::class)->for($run);

    $response = $this->get($link)->assertOk()->assertHeader('Content-Type', 'application/zip');
    expect($response->streamedContent())->toBe('encrypted zip bytes')
        ->and($response->headers->get('Content-Disposition'))->toContain('goproperty-db-2026-11-09-01-30-00.zip');

    $audit = AuditEntry::query()->where('event', 'backup.downloaded')->sole();
    expect($audit->causer_id)->toBe($this->tayo->id)
        ->and($audit->properties['filename'])->toBe('goproperty-db/2026-11-09-01-30-00.zip');

    // The link alone is not enough: unsigned, expired, or someone without the permission.
    $this->get(route(DownloadLinks::ROUTE, ['run' => $run->id]))->assertForbidden();
    $this->travel(16)->minutes();
    $this->get($link)->assertForbidden();
    $this->travelBack();
    $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00', 'Africa/Lagos'));
    $this->flushSession();
    $this->actingAs(staffNamed('Sola', ['support-agent']), 'staff')->get(app(DownloadLinks::class)->for($run))->assertForbidden();

    expect(AuditEntry::query()->where('event', 'backup.downloaded')->count())->toBe(1);
});

it('says when a backup to download is no longer at its destination', function () {
    $run = folderBackup($this->root);
    File::delete($this->root.'/'.$run->filename);

    $this->actingAs($this->tayo, 'staff')->get(app(DownloadLinks::class)->for($run))->assertNotFound();
});

it('asks for a production restore with a step-up, the typed name and a reason, then waits for a super-admin', function () {
    $run = folderBackup($this->root);
    $operations = app(BackupOperations::class);
    $this->actingAs($this->tayo, 'staff');

    // Without confirming the password and code first.
    expect(fn () => $operations->requestProductionRestore($this->tayo, $run, '2026-11-09-01-30-00', 'Rows deleted by mistake at 09:12'))
        ->toThrow(BackupRefused::class, 'Confirm with your password and authenticator code first.');

    stepUpAs($this->tayo);
    expect(fn () => $operations->requestProductionRestore($this->tayo, $run, 'yes', 'Rows deleted by mistake at 09:12'))
        ->toThrow(BackupRefused::class, 'Type the backup\'s name exactly to confirm: 2026-11-09-01-30-00.')
        ->and(fn () => $operations->requestProductionRestore($this->tayo, $run, '2026-11-09-01-30-00', 'oops'))
        ->toThrow(BackupRefused::class, 'Give a reason of 10 to 500 characters.');

    // Through the screen: password, code, the backup's name and a reason.
    Livewire::test(ListBackups::class)
        ->callTableAction('restoreProduction', $run, ['password' => 'x-long-password-1', 'code' => backupStaffCode($this->tayo), 'confirmation' => '2026-11-09-01-30-00', 'reason' => 'Rows deleted by mistake at 09:12'])
        ->assertHasNoTableActionErrors()
        ->assertNotified('Approval requested: a super-admin decides it in Staff → Approvals.');

    $operation = BackupOperation::query()->sole();
    $request = ApprovalRequest::query()->sole();
    expect($operation->status)->toBe(OperationStatus::AwaitingApproval)
        ->and($operation->approval_request_id)->toBe($request->id)
        ->and($request->action)->toBe(ApprovalAction::ProductionRestore)
        ->and($request->summary)->toContain('Restore production from goproperty-db/2026-11-09-01-30-00.zip')
        ->and($request->expires_at->equalTo(now()->addDay()))->toBeTrue();

    // A second request while one is open is refused.
    expect(fn () => $operations->requestProductionRestore($this->tayo, $run, '2026-11-09-01-30-00', 'Rows deleted by mistake again'))
        ->toThrow(BackupRefused::class, 'A restore to production is already waiting for approval, queued or running.');

    // The scheduler does not start it without the approval.
    expect(app(OperationRunner::class)->runDue())->toBe(0)
        ->and($operation->fresh()->status)->toBe(OperationStatus::AwaitingApproval);

    // The requester cannot approve it, nor can another technical admin.
    expect(fn () => app(ApprovalService::class)->approve($request, $this->tayo))->toThrow(ApprovalRefused::class, 'You cannot decide your own request')
        ->and(fn () => app(ApprovalService::class)->approve($request, staffNamed('Kunle', ['technical-admin'])))->toThrow(ApprovalRefused::class, 'You do not have the permission');

    // A super-admin approves in Staff → Approvals, confirming their password and code.
    $ada = staffNamed('Adaeze', ['super-admin']);
    $this->flushSession();
    $this->actingAs($ada, 'staff');
    Livewire::test(ListApprovalRequests::class)
        ->callTableAction('approve', $request, ['password' => 'wrong-password', 'code' => backupStaffCode($ada)])
        ->assertHasNoTableActionErrors()
        ->assertNotified('The password or code is not right.');
    expect($request->fresh()->status)->toBe(ApprovalStatus::Pending);

    Livewire::test(ListApprovalRequests::class)
        ->callTableAction('approve', $request, ['password' => 'x-long-password-1', 'code' => backupStaffCode($ada)])
        ->assertNotified('Approved: the restore starts within a minute, with the site in maintenance mode.');

    expect($request->fresh()->status)->toBe(ApprovalStatus::Approved)
        ->and($operation->fresh()->status)->toBe(OperationStatus::Queued)
        ->and($operation->fresh()->approved_by_staff_id)->toBe($ada->id)
        ->and(AuditEntry::query()->where('log_name', 'backups')->pluck('event')->all())->toBe(['backup.production_restore_requested', 'backup.production_restore_approved']);
});

it('cancels the restore when the approval is rejected or expires', function () {
    $operations = app(BackupOperations::class);
    $approvals = app(ApprovalService::class);
    $ada = staffNamed('Adaeze', ['super-admin']);

    stepUpAs($this->tayo);
    $first = $operations->requestProductionRestore($this->tayo, folderBackup($this->root, '2026-11-08-01-30-00'), '2026-11-08-01-30-00', 'Rows deleted by mistake at 09:12');
    $approvals->reject(ApprovalRequest::query()->findOrFail($first->approval_request_id), $ada, 'Restore just the table instead');

    expect($first->fresh()->status)->toBe(OperationStatus::Cancelled)
        ->and($first->fresh()->result)->toBe('The approval was rejected.');

    $second = $operations->requestProductionRestore($this->tayo, folderBackup($this->root, '2026-11-09-01-30-00'), '2026-11-09-01-30-00', 'Rows deleted by mistake at 09:12');
    $this->travel(25)->hours();
    expect($approvals->expireStale())->toBe(1)
        ->and($second->fresh()->status)->toBe(OperationStatus::Cancelled)
        ->and($second->fresh()->result)->toBe('The approval expired without a decision.')
        ->and(app(OperationRunner::class)->runDue())->toBe(0);
});

it('needs a verify database and a staging database before queuing a verify or a staging restore', function () {
    $run = folderBackup($this->root);
    $operations = app(BackupOperations::class);
    config(['backup.verify_database' => null, 'backup.staging_database' => null]);

    expect(fn () => $operations->queueVerify($this->tayo, $run))->toThrow(BackupRefused::class, 'BACKUP_VERIFY_DB_DATABASE')
        ->and(fn () => $operations->queueStagingRestore($this->tayo, $run, 'Investigating ticket 812'))->toThrow(BackupRefused::class, 'BACKUP_STAGING_DB_DATABASE');

    config(['backup.staging_database' => config('database.connections.'.config('database.default').'.database')]);
    expect(fn () => $operations->queueStagingRestore($this->tayo, $run, 'Investigating ticket 812'))->toThrow(BackupRefused::class, 'names this site\'s own database');

    config(['backup.verify_database' => 'gp_verify', 'backup.staging_database' => 'gp_staging']);
    $this->actingAs($this->tayo, 'staff');
    Livewire::test(ListBackups::class)->callTableAction('verify', $run)->assertNotified('Verify queued: it starts within a minute.');
    Livewire::test(ListBackups::class)->callTableAction('restoreStaging', $run, ['reason' => 'Investigating ticket 812'])->assertNotified('Restore to staging queued: it starts within a minute.');

    expect(BackupOperation::query()->orderBy('id')->get()->map(fn (BackupOperation $operation): array => [$operation->kind, $operation->target_database])->all())
        ->toBe([[OperationKind::Verify, 'gp_verify'], [OperationKind::RestoreStaging, 'gp_staging']]);
});

it('only verifies or restores a successful database backup', function () {
    $files = folderBackup($this->root);
    $files->forceFill(['type' => 'files', 'filename' => 'goproperty-files/2026-11-09-02-30-00.zip'])->save();
    config(['backup.verify_database' => 'gp_verify']);

    expect(fn () => app(BackupOperations::class)->queueVerify($this->tayo, $files))->toThrow(BackupRefused::class, 'Only a successful database backup');

    $this->actingAs($this->tayo, 'staff');
    Livewire::test(ListBackups::class)
        ->assertTableActionHidden('verify', $files)
        ->assertTableActionHidden('restoreProduction', $files)
        ->assertTableActionVisible('download', $files);
});

it('lets staff withdraw a queued request, and fails one whose process died', function () {
    $operation = app(BackupOperations::class)->queueBackup($this->tayo, 'files');
    $this->actingAs($this->tayo, 'staff');

    Livewire::test(ListBackupOperations::class)->callTableAction('cancel', $operation)->assertNotified('Withdrawn.');
    expect($operation->fresh()->status)->toBe(OperationStatus::Cancelled)
        ->and($operation->fresh()->result)->toBe('Withdrawn by Tayo.');

    Log::spy();
    $stuck = app(BackupOperations::class)->queueBackup($this->tayo, 'files');
    $stuck->forceFill(['status' => OperationStatus::Running, 'started_at' => now()->subHours(7)])->save();

    app(OperationRunner::class)->runDue();

    expect($stuck->fresh()->status)->toBe(OperationStatus::Failed)
        ->and($stuck->fresh()->result)->toContain('did not finish within 6 hours');
    Log::shouldHaveReceived('critical')->once();
});

it('records a restore drill done by hand', function () {
    $this->actingAs($this->tayo, 'staff');

    expect(fn () => app(BackupOperations::class)->recordDrill($this->tayo, '2026-11-11', true, 'Restored to staging and checked the payments'))
        ->toThrow(BackupRefused::class, 'today or within the last year');

    Livewire::test(ListBackups::class)
        ->callTableAction('recordDrill', data: ['date' => '2026-11-07', 'passed' => '1', 'notes' => 'Restored Friday\'s backup to staging; payments and receipts checked'])
        ->assertNotified('Drill recorded.');

    $drill = BackupOperation::query()->sole();
    expect($drill->kind)->toBe(OperationKind::Drill)
        ->and($drill->status)->toBe(OperationStatus::Succeeded)
        ->and($drill->drill_date?->toDateString())->toBe('2026-11-07');

    $this->get($this->console.'/backups')->assertSee('Last passed drill: 7 Nov 2026');
});

it('adds, changes, checks and switches off destinations without ever showing a credential', function () {
    $this->actingAs($this->tayo, 'staff');

    Livewire::test(ListBackupDestinations::class)
        ->callTableAction('add', data: [
            'name' => 'Cloudflare R2', 'driver' => 's3', 'applies_to' => 'both',
            's3' => ['bucket' => 'gp-backups', 'endpoint' => 'https://acct.r2.cloudflarestorage.com', 'key' => 'AKIA-first', 'secret' => 'first-secret-value-123'],
        ])
        ->assertNotified('"Cloudflare R2" added. Check it before the next backup.');

    $r2 = BackupDestination::query()->sole();
    expect($r2->config)->toMatchArray(['bucket' => 'gp-backups', 'key' => 'AKIA-first', 'secret' => 'first-secret-value-123', 'root' => 'goproperty-backups', 'region' => 'auto']);

    $this->get($this->console.'/backup-destinations')->assertOk()->assertSee('gp-backups/goproperty-backups at acct.r2.cloudflarestorage.com')
        ->assertDontSee('first-secret-value-123')->assertDontSee('AKIA-first');

    // A change with the credentials left blank keeps them.
    Livewire::test(ListBackupDestinations::class)
        ->mountTableAction('change', $r2)
        ->assertTableActionDataSet(['name' => 'Cloudflare R2', 's3.bucket' => 'gp-backups', 's3.secret' => null])
        ->setTableActionData(['name' => 'R2 (Lagos)', 's3' => ['bucket' => 'gp-backups-2', 'endpoint' => 'https://acct.r2.cloudflarestorage.com', 'key' => '', 'secret' => '']])
        ->callMountedTableAction()
        ->assertNotified('Saved. Check it again.');

    expect($r2->fresh()->name)->toBe('R2 (Lagos)')
        ->and($r2->fresh()->config)->toMatchArray(['bucket' => 'gp-backups-2', 'key' => 'AKIA-first', 'secret' => 'first-secret-value-123']);

    $entries = AuditEntry::query()->where('log_name', 'backups')->get();
    expect($entries->pluck('event')->all())->toBe(['backup_destination.created', 'backup_destination.updated'])
        ->and(json_encode($entries->pluck('properties')))->not->toContain('first-secret-value-123')->not->toContain('AKIA-first')
        ->and($entries[1]->properties['credentials_replaced'])->toBe([]);

    // Check: a working folder, and one that cannot be written.
    $folder = app(Destinations::class)->create($this->tayo, ['name' => 'Drill folder', 'driver' => 'local', 'applies_to' => 'db', 'root' => $this->root]);
    $broken = app(Destinations::class)->create($this->tayo, ['name' => 'Broken', 'driver' => 'local', 'applies_to' => 'db', 'root' => '/proc/goproperty-cannot-write']);

    Livewire::test(ListBackupDestinations::class)->callTableAction('check', $folder)->assertNotified('It works: a test file was written, read back and deleted.');
    Livewire::test(ListBackupDestinations::class)->callTableAction('check', $broken)->assertNotified('The check failed');
    expect($folder->fresh()->check_problem)->toBeNull()
        ->and($folder->fresh()->checked_at)->not->toBeNull()
        ->and($broken->fresh()->check_problem)->not->toBeNull();

    Livewire::test(ListBackupDestinations::class)->callTableAction('switch', $broken)->assertNotified('Switched off.');
    expect($broken->fresh()->active)->toBeFalse()
        ->and($broken->fresh()->takes('db'))->toBeFalse();
});

it('takes a Google Drive service account key and refuses a bad one', function () {
    $destinations = app(Destinations::class);
    $key = ['type' => 'service_account', 'client_email' => 'backups@gp.iam.gserviceaccount.com', 'private_key' => "-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----\n"];

    expect(fn () => $destinations->create($this->tayo, ['name' => 'Drive', 'driver' => 'google_drive', 'shared_drive_id' => '0AB', 'service_account' => '{"not": "a key"}']))
        ->toThrow(BackupRefused::class, 'The service account key is the JSON file');

    $drive = $destinations->create($this->tayo, ['name' => 'Drive', 'driver' => 'google_drive', 'applies_to' => 'db', 'shared_drive_id' => '0AB', 'service_account' => json_encode($key)]);
    expect($drive->config['service_account'])->toBe($key)
        ->and($drive->applies_to)->toBe('db');

    // Kept as it was when the change leaves it blank.
    $destinations->update($this->tayo, $drive, ['name' => 'Drive', 'applies_to' => 'db', 'shared_drive_id' => '0AC', 'service_account' => '']);
    expect($drive->fresh()->config)->toMatchArray(['shared_drive_id' => '0AC', 'service_account' => $key]);
});

it('refuses staff without the permission, whatever the screen shows', function () {
    $run = folderBackup($this->root);
    $sola = staffNamed('Sola', ['support-agent']);

    expect(fn () => app(BackupOperations::class)->queueBackup($sola, 'db'))->toThrow(BackupRefused::class, 'You do not have the permission')
        ->and(fn () => app(BackupOperations::class)->queueVerify($sola, $run))->toThrow(BackupRefused::class, 'You do not have the permission')
        ->and(fn () => app(Destinations::class)->create($sola, ['name' => 'X', 'driver' => 'local', 'root' => $this->root]))->toThrow(BackupRefused::class, 'You do not have the permission');
});
