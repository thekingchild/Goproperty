<?php

declare(strict_types=1);

use App\Backup\BackupDestination;
use App\Backup\BackupRun;
use App\Backup\Restorer;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/*
 * P00-12 acceptance (PLATFORM_CONSOLE.md § 4): the nightly database backup
 * runs from the scheduler on a host without exec, proc_open or pcntl,
 * lands as an AES-256 encrypted zip at every destination, and decrypts and
 * restores into another database. Drill: the dump reads committed rows
 * over its own connection, so this test commits and truncates.
 */

const BACKUP_DRILL_DISABLED_FUNCTIONS = 'exec,passthru,shell_exec,system,proc_open,popen,'
    .'pcntl_exec,pcntl_fork,pcntl_alarm,pcntl_signal,pcntl_async_signals';

beforeEach(function () {
    $this->folder = sys_get_temp_dir().'/goproperty-backup-drill-'.Str::random(8);
    $this->restoreDb = DB::connection()->getDatabaseName().'_restore';
    DB::statement('CREATE DATABASE IF NOT EXISTS `'.$this->restoreDb.'`');
});

afterEach(function () {
    DB::statement('DROP DATABASE IF EXISTS `'.$this->restoreDb.'`');
    File::deleteDirectory($this->folder);

    foreach (['backup_runs', 'backup_destinations', 'users', 'activity_log'] as $table) {
        DB::table($table)->delete();
    }

    DB::table('audit_chain_head')->update(['last_hash' => null]);
});

function backupSubprocess(array $command): ProcessResult
{
    $database = DB::connection();

    return Process::path(base_path())->timeout(120)->env([
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => $database->getName(),
        'DB_HOST' => (string) $database->getConfig('host'),
        'DB_PORT' => (string) $database->getConfig('port'),
        'DB_DATABASE' => $database->getDatabaseName(),
        'DB_USERNAME' => (string) $database->getConfig('username'),
        'DB_PASSWORD' => (string) $database->getConfig('password'),
    ])->run([PHP_BINARY, '-d', 'disable_functions='.BACKUP_DRILL_DISABLED_FUNCTIONS, 'artisan', ...$command, '--no-ansi']);
}

it('backs up the database nightly without exec, encrypted, to every destination, and restores it', function () {
    $marker = User::factory()->create(['name' => 'Backup Drill Marker', 'email' => 'drill-'.Str::random(6).'@example.com']);
    $folder = BackupDestination::query()->create(['name' => 'Drill folder', 'driver' => 'local', 'config' => ['root' => $this->folder.'/one'], 'applies_to' => 'db']);
    $second = BackupDestination::query()->create(['name' => 'Second folder', 'driver' => 'local', 'config' => ['root' => $this->folder.'/two'], 'applies_to' => 'both']);
    BackupDestination::query()->create(['name' => 'Files only', 'driver' => 'local', 'config' => ['root' => $this->folder.'/three'], 'applies_to' => 'files']);

    // The job itself, as a shared host runs it: no exec, proc_open or pcntl.
    $run = backupSubprocess(['goproperty:backup-run', 'db']);
    expect($run->successful())->toBeTrue($run->output().$run->errorOutput());

    // The nightly schedule (schedule:run itself is drilled in CronDrillTest).
    $event = collect(app(Schedule::class)->events())->first(fn ($event) => $event->description === 'backup-db');
    expect($event?->expression)->toBe('30 1 * * *');
    $event->run(app());

    $runs = BackupRun::query()->orderBy('id')->get();
    expect($runs->pluck('status')->all())->toBe(['success', 'success', 'success', 'success'])
        ->and($runs->pluck('backup_destination_id')->all())->toBe([$folder->id, $second->id, $folder->id, $second->id])
        ->and($runs->pluck('triggered_by')->all())->toBe(['manual', 'manual', 'schedule', 'schedule'])
        ->and(is_dir($this->folder.'/three'))->toBeFalse('a files-only destination takes no database backup');

    foreach (['one', 'two'] as $where) {
        $zips = glob($this->folder.'/'.$where.'/goproperty-db/*.zip') ?: [];
        expect($zips)->not->toBeEmpty();

        $zip = new ZipArchive;
        $zip->open($zips[0]);
        $entry = $zip->statIndex(0);
        expect($entry['name'])->toStartWith('db-dumps/')
            ->and($entry['encryption_method'])->toBe(ZipArchive::EM_AES_256)
            ->and($zip->getFromIndex(0))->toBeFalse('readable without the password');
        $zip->close();
    }

    $this->artisan('goproperty:backup-restore', ['source' => 'latest', '--database' => $this->restoreDb, '--force' => true])
        ->expectsOutputToContain("Restored into \"{$this->restoreDb}\"")
        ->assertSuccessful();

    $restored = DB::connection()->select('SELECT name FROM `'.$this->restoreDb.'`.users WHERE id = ?', [$marker->id]);
    expect($restored[0]->name ?? null)->toBe('Backup Drill Marker')
        ->and(app(Restorer::class)->tables($this->restoreDb))->toContain('users', 'migrations', 'activity_log', 'backup_runs');
});

it('verifies the newest backup by restoring it into the verify database', function () {
    BackupDestination::query()->create(['name' => 'Drill folder', 'driver' => 'local', 'config' => ['root' => $this->folder], 'applies_to' => 'both']);
    $this->artisan('goproperty:backup-run', ['type' => 'db'])->assertSuccessful();

    config(['backup.verify_database' => $this->restoreDb]);

    $this->artisan('goproperty:backup-verify')->expectsOutputToContain('restored into')->assertSuccessful();
    expect(BackupRun::query()->sole()->verified_at)->not->toBeNull();
});

it('cannot be opened with another key', function () {
    BackupDestination::query()->create(['name' => 'Drill folder', 'driver' => 'local', 'config' => ['root' => $this->folder], 'applies_to' => 'both']);
    $this->artisan('goproperty:backup-run', ['type' => 'db'])->assertSuccessful();
    $zip = (glob($this->folder.'/goproperty-db/*.zip') ?: [])[0];

    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

    expect(fn () => app(Restorer::class)->extractDump($zip, $this->folder))->toThrow(RuntimeException::class, 'could not be decrypted');
});

it('never restores into the site\'s own database', function () {
    BackupDestination::query()->create(['name' => 'Drill folder', 'driver' => 'local', 'config' => ['root' => $this->folder], 'applies_to' => 'both']);
    $this->artisan('goproperty:backup-run', ['type' => 'db'])->assertSuccessful();

    $this->artisan('goproperty:backup-restore', ['source' => 'latest', '--database' => DB::connection()->getDatabaseName(), '--force' => true])
        ->expectsOutputToContain('Refusing to restore into the site\'s own database')
        ->assertFailed();
});
