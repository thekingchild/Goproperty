<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Backup\ArchivePassword;
use App\Backup\BackupDestination;
use App\Backup\BackupRun;
use App\Backup\BackupRunner;
use App\Backup\Operations\BackupOperation;
use App\Backup\Operations\BackupOperations;
use App\Backup\Operations\OperationRunner;
use App\Backup\Operations\OperationStatus;
use App\Backup\Restorer;
use App\Models\ApprovalRequest;
use App\Models\PlatformStaff;
use App\Models\User;
use App\Platform\Approvals\ApprovalService;
use App\Platform\Console\StepUp;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/*
 * P06-10 acceptance (PLATFORM_CONSOLE.md § 4): "Backup verify restores the
 * latest dump into a temporary database; production restore cannot start
 * without a second approver." Drill: dumps read committed rows and the
 * restores replace whole databases (the site's own, for production), so
 * this test commits and truncates.
 */

beforeEach(function () {
    // Truncation empties the audit chain's head row, which a migration seeds.
    DB::table('audit_chain_head')->insertOrIgnore(['id' => 1, 'last_hash' => null, 'updated_at' => now()]);
    seedStaffRoles();
    $this->folder = sys_get_temp_dir().'/goproperty-console-drill-'.Str::random(8);
    $this->live = DB::connection()->getDatabaseName();
    $this->verifyDb = $this->live.'_verify';
    $this->stagingDb = $this->live.'_staging';

    foreach ([$this->verifyDb, $this->stagingDb] as $database) {
        DB::statement('CREATE DATABASE IF NOT EXISTS `'.$database.'`');
    }

    config(['backup.verify_database' => $this->verifyDb, 'backup.staging_database' => $this->stagingDb]);
    $this->destination = BackupDestination::query()->create(['name' => 'Drill folder', 'driver' => 'local', 'config' => ['root' => $this->folder], 'applies_to' => 'both']);
    $this->tayo = staffNamed('Tayo', ['technical-admin']);
    $this->ada = staffNamed('Adaeze', ['super-admin']);

    // Maintenance mode is recorded, not written to storage/framework, which
    // the other test processes share.
    $this->maintenance = new class implements MaintenanceMode
    {
        /** @var list<string> */
        public array $calls = [];

        private bool $down = false;

        public function activate(array $payload): void
        {
            $this->calls[] = 'down';
            $this->down = true;
        }

        public function deactivate(): void
        {
            $this->calls[] = 'up';
            $this->down = false;
        }

        public function active(): bool
        {
            return $this->down;
        }

        public function data(): array
        {
            return [];
        }
    };
    app()->instance(MaintenanceMode::class, $this->maintenance);
});

afterEach(function () {
    foreach ([$this->verifyDb, $this->stagingDb] as $database) {
        DB::statement('DROP DATABASE IF EXISTS `'.$database.'`');
    }

    File::deleteDirectory($this->folder);
    DB::table('audit_chain_head')->update(['last_hash' => null]);
});

function drillBackup(): BackupRun
{
    $run = app(BackupRunner::class)->run('db', 'manual')->sole();
    expect($run->status)->toBe('success', (string) $run->notes);

    return $run;
}

function restoredTablesIn(string $database): array
{
    return app(Restorer::class)->tables($database);
}

/** Asks for a production restore as Tayo (password and code confirmed) and approves it as Adaeze. */
function approvedProductionRestore(BackupRun $run, PlatformStaff $requester, PlatformStaff $approver): BackupOperation
{
    test()->actingAs($requester, 'staff');
    expect(app(StepUp::class)->confirm($requester, 'x-long-password-1', app(Google2FA::class)->getCurrentOtp((string) $requester->two_factor_secret)))->toBeNull();

    $operations = app(BackupOperations::class);
    $operation = $operations->requestProductionRestore($requester, $run, $operations->confirmationPhrase($run), 'Rows deleted by mistake at 09:12');

    // Not without the second person.
    expect(app(OperationRunner::class)->runDue())->toBe(0)
        ->and($operation->fresh()->status)->toBe(OperationStatus::AwaitingApproval);

    app(ApprovalService::class)->approve(ApprovalRequest::query()->findOrFail($operation->approval_request_id), $approver);
    expect($operation->fresh()->status)->toBe(OperationStatus::Queued);

    return $operation;
}

it('acceptance: verifies a backup by restoring it into the verify database, then empties it', function () {
    $marker = User::factory()->create(['name' => 'Verify Marker']);
    $run = drillBackup();

    app(BackupOperations::class)->queueVerify($this->tayo, $run);
    expect(app(OperationRunner::class)->runDue())->toBe(1);

    $operation = BackupOperation::query()->sole();
    expect($operation->status)->toBe(OperationStatus::Succeeded, (string) $operation->result)
        ->and($operation->result)->toContain("restored into \"{$this->verifyDb}\"")->toContain('the verify database was emptied again')
        ->and($run->fresh()->verified_at)->not->toBeNull()
        ->and(restoredTablesIn($this->verifyDb))->toBe([])
        ->and(User::query()->whereKey($marker->id)->exists())->toBeTrue();
});

it('verifies monthly as an operation the console lists', function () {
    drillBackup();

    $result = app(OperationRunner::class)->monthlyVerify();

    expect($result['ok'])->toBeTrue($result['message'])
        ->and(BackupOperation::query()->sole()->status)->toBe(OperationStatus::Succeeded)
        ->and(BackupOperation::query()->sole()->requested_by_staff_id)->toBeNull();
});

it('restores a backup into the staging database, leaving the live site alone', function () {
    $marker = User::factory()->create(['name' => 'Staging Marker']);
    $run = drillBackup();
    $marker->forceFill(['name' => 'Changed after the backup'])->save();

    app(BackupOperations::class)->queueStagingRestore($this->tayo, $run, 'Investigating ticket 812');
    app(OperationRunner::class)->runDue();

    $operation = BackupOperation::query()->sole();
    $staged = DB::connection()->select('SELECT name FROM `'.$this->stagingDb.'`.users WHERE id = ?', [$marker->id]);

    expect($operation->status)->toBe(OperationStatus::Succeeded, (string) $operation->result)
        ->and($staged[0]->name ?? null)->toBe('Staging Marker')
        ->and($marker->fresh()->name)->toBe('Changed after the backup')
        ->and($this->maintenance->calls)->toBe([]);
});

it('acceptance: restores production only after a second approver, behind maintenance mode and a safety backup', function () {
    $before = User::factory()->create(['name' => 'Written before the backup']);
    $run = drillBackup();
    $this->travel(1)->minutes();
    $after = User::factory()->create(['name' => 'Written after the backup']);
    DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => $after->id, 'payload' => '', 'last_activity' => now()->getTimestamp()]);

    $operation = approvedProductionRestore($run, $this->tayo, $this->ada);
    expect(app(OperationRunner::class)->runDue())->toBe(1);

    $operation = BackupOperation::query()->findOrFail($operation->id);
    $runs = BackupRun::query()->orderBy('id')->get();

    expect($operation->status)->toBe(OperationStatus::Succeeded, (string) $operation->result)
        ->and($operation->approved_by_staff_id)->toBe($this->ada->id)
        // The database is as it was at the backup...
        ->and(User::query()->whereKey($before->id)->exists())->toBeTrue()
        ->and(User::query()->whereKey($after->id)->exists())->toBeFalse()
        // ...nobody stays signed in from before...
        ->and(DB::table('sessions')->count())->toBe(0)
        // ...and the backups list kept every backup, the safety backup taken first included.
        ->and($runs->pluck('triggered_by')->all())->toBe(['manual', 'safety'])
        ->and($runs->pluck('status')->all())->toBe(['success', 'success'])
        ->and($operation->safety_backup_filename)->toBe($runs[1]->filename)
        ->and(is_file($this->folder.'/'.$runs[1]->filename))->toBeTrue()
        ->and($operation->result)->toContain("Production was restored from {$run->filename}")
        ->and($this->maintenance->calls)->toBe(['down', 'up']);

    $audit = AuditEntry::query()->where('event', 'backup.production_restored')->sole();
    expect($audit->properties['safety_backup'])->toBe($runs[1]->filename)
        ->and($audit->properties['approved_by_staff_id'])->toBe($this->ada->id);

    // The safety backup holds what the restore replaced.
    app(Restorer::class)->import(app(Restorer::class)->extractDump(app(Restorer::class)->download($this->destination, (string) $runs[1]->filename, $this->folder)['path'], $this->folder), $this->verifyDb);
    expect(DB::connection()->select('SELECT name FROM `'.$this->verifyDb.'`.users WHERE id = ?', [$after->id])[0]->name ?? null)->toBe('Written after the backup');
});

it('puts the safety backup back when a restore to production fails half way', function () {
    $marker = User::factory()->create(['name' => 'Must survive']);
    // A backup whose dump fails after the tables were dropped. Dated in the
    // past, as every real backup is: the runner records the newest file at a
    // destination as the one it just made.
    $name = 'goproperty-db/'.now()->subDay()->format('Y-m-d-H-i-s').'.zip';
    File::ensureDirectoryExists($this->folder.'/goproperty-db');
    $zip = new ZipArchive;
    $zip->open($this->folder.'/'.$name, ZipArchive::CREATE);
    $zip->addFromString('db-dumps/broken.sql', "-- broken dump\nCREATE TABLE `drill_broken` (`id` INT);\nINSERT INTO `no_such_table` VALUES (1);\n");
    $zip->setEncryptionName('db-dumps/broken.sql', ZipArchive::EM_AES_256, app(ArchivePassword::class)->get());
    $zip->close();
    $broken = BackupRun::query()->create([
        'type' => 'db', 'filename' => $name, 'size_bytes' => 100, 'backup_destination_id' => $this->destination->id,
        'status' => 'success', 'started_at' => now(), 'finished_at' => now(), 'triggered_by' => 'schedule',
    ]);

    $operation = approvedProductionRestore($broken, $this->tayo, $this->ada);
    app(OperationRunner::class)->runDue();

    $operation = BackupOperation::query()->findOrFail($operation->id);
    expect($operation->status)->toBe(OperationStatus::Failed)
        ->and($operation->result)->toContain('The restore failed, and the site was put back as it was from the safety backup')
        ->and($marker->fresh()?->name)->toBe('Must survive')
        ->and(DB::connection()->getSchemaBuilder()->hasTable('drill_broken'))->toBeFalse()
        ->and($this->maintenance->calls)->toBe(['down', 'up']);
});

it('restores nothing when the backup is gone from its destination', function () {
    $marker = User::factory()->create(['name' => 'Untouched']);
    $run = drillBackup();
    File::delete($this->folder.'/'.$run->filename);

    $operation = approvedProductionRestore($run, $this->tayo, $this->ada);
    app(OperationRunner::class)->runDue();

    expect($operation->fresh()->status)->toBe(OperationStatus::Failed)
        ->and($operation->fresh()->result)->toContain('Nothing was restored')->toContain('no longer at Drill folder')
        ->and($marker->fresh()?->name)->toBe('Untouched')
        // Found before the site went down.
        ->and($this->maintenance->calls)->toBe([]);
});
