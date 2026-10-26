<?php

declare(strict_types=1);

use App\Backup\ArchivePassword;
use App\Backup\BackupDestination;
use App\Backup\BackupRun;
use App\Backup\BackupRunner;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * P00-12: backup runs, destinations and retention (PLATFORM_CONSOLE.md § 4).
 * The end-to-end backup and restore is drilled in tests/Drill/BackupDrillTest.php.
 */

function s3Destination(array $config = []): BackupDestination
{
    return BackupDestination::query()->create([
        'name' => 'R2',
        'driver' => 's3',
        'config' => $config + ['key' => 'AKIA-test', 'secret' => 'super-secret-value', 'bucket' => 'gp-backups', 'endpoint' => 'https://acct.r2.cloudflarestorage.com', 'region' => 'auto', 'use_path_style_endpoint' => true],
        'applies_to' => 'both',
    ]);
}

it('records a failed run when there is nowhere to back up to', function () {
    Log::spy();

    $runs = app(BackupRunner::class)->run('db');

    expect($runs)->toHaveCount(1)
        ->and($runs[0]->status)->toBe('failed')
        ->and($runs[0]->notes)->toBe('No active backup destination.');
    Log::shouldHaveReceived('critical')->withArgs(fn (string $message) => str_contains($message, 'no active backup destination'))->once();
});

it('copies the encrypted zip to an S3-compatible destination', function () {
    $destination = s3Destination();
    Storage::fake($destination->diskName());

    $runs = app(BackupRunner::class)->run('db', 'manual');

    expect($runs[0]->status)->toBe('success')
        ->and($runs[0]->filename)->toStartWith('goproperty-db/')
        ->and($runs[0]->size_bytes)->toBeGreaterThan(0)
        ->and(Storage::disk($destination->diskName())->exists((string) $runs[0]->filename))->toBeTrue();
});

it('works in a temporary folder of its own, so it cannot empty another run\'s', function () {
    // Spatie empties <temporary_directory>/temp when a job starts; with the
    // shared default folder a manual run would wipe the nightly run's zip.
    $shared = storage_path('app/backup-temp/temp');
    File::ensureDirectoryExists($shared);
    File::put($shared.'/other-run.zip', 'zip in progress');
    $destination = s3Destination();
    Storage::fake($destination->diskName());

    try {
        expect(app(BackupRunner::class)->run('db')[0]->status)->toBe('success')
            ->and(File::get($shared.'/other-run.zip'))->toBe('zip in progress')
            ->and(config('backup.backup.temporary_directory'))->toBe(storage_path('app/backup-temp'));
    } finally {
        File::delete($shared.'/other-run.zip');
    }
});

it('keeps going when one destination fails', function () {
    Log::spy();
    $broken = BackupDestination::query()->create(['name' => 'Broken', 'driver' => 'local', 'config' => ['root' => '/proc/goproperty-cannot-write'], 'applies_to' => 'db']);
    $working = s3Destination();
    Storage::fake($working->diskName());

    $runs = app(BackupRunner::class)->run('db');

    expect($runs->pluck('status', 'backup_destination_id')->all())->toBe([$broken->id => 'failed', $working->id => 'success'])
        ->and($runs[0]->notes)->not->toBeEmpty();
    Log::shouldHaveReceived('critical')->withArgs(fn (string $message) => str_contains($message, 'failed at '.$broken->diskName()))->once();
});

it('skips inactive destinations and those for the other job', function () {
    $files = BackupDestination::query()->create(['name' => 'Files', 'driver' => 'local', 'config' => ['root' => sys_get_temp_dir().'/gp-'.Str::random(6)], 'applies_to' => 'files']);
    $off = s3Destination();
    $off->update(['active' => false]);

    expect(app(BackupRunner::class)->run('db')->sole()->notes)->toBe('No active backup destination.')
        ->and($files->takes('files'))->toBeTrue()
        ->and($files->takes('db'))->toBeFalse();
});

it('deletes backups older than the retention at the destination', function () {
    $destination = s3Destination();
    $disk = Storage::fake($destination->diskName());
    $old = 'goproperty-db/'.now()->subDays(400)->format('Y-m-d-H-i-s').'.zip';
    $disk->put($old, 'old');
    touch($disk->path($old), now()->subDays(400)->getTimestamp());

    app(BackupRunner::class)->run('db');

    expect($disk->exists($old))->toBeFalse()
        ->and($disk->files('goproperty-db'))->toHaveCount(1);
});

it('stores destination credentials encrypted and never shows them', function () {
    $destination = s3Destination();

    $raw = (string) DB::table('backup_destinations')->where('id', $destination->id)->value('config');

    expect($raw)->not->toContain('super-secret-value')
        ->and($destination->toArray())->not->toHaveKey('config')
        ->and($destination->fresh()->diskConfig())->toMatchArray([
            'driver' => 's3', 'key' => 'AKIA-test', 'secret' => 'super-secret-value', 'bucket' => 'gp-backups',
            'endpoint' => 'https://acct.r2.cloudflarestorage.com', 'use_path_style_endpoint' => true, 'root' => 'goproperty-backups',
        ]);
});

it('refuses a local folder as a destination in production', function () {
    $destination = BackupDestination::query()->create(['name' => 'Same server', 'driver' => 'local', 'config' => [], 'applies_to' => 'both']);
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => $destination->diskConfig())->toThrow(InvalidArgumentException::class, 'not a backup destination in production');
});

it('derives the archive password from APP_KEY unless one is set', function () {
    $password = app(ArchivePassword::class);
    $derived = $password->get();

    expect($derived)->toBe($password->get())
        ->and(strlen($derived))->toBeGreaterThanOrEqual(40)
        ->and($derived)->not->toContain((string) config('app.key'));

    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    expect($password->get())->not->toBe($derived);

    config(['backup.archive_password' => 'a-long-explicit-password-123']);
    expect($password->get())->toBe('a-long-explicit-password-123');

    config(['backup.archive_password' => 'short']);
    expect($password->get())->not->toBe('short');
});

it('schedules the database and files backups as separate nightly jobs and a monthly verify', function () {
    $events = collect(app(Schedule::class)->events())->keyBy('description');

    expect($events['backup-db']->expression)->toBe('30 1 * * *')
        ->and($events['backup-files']->expression)->toBe('30 2 * * *')
        ->and($events['backup-verify']->expression)->toBe('0 4 1 * *');
});

it('explains a missing verify database', function () {
    $this->artisan('goproperty:backup-verify')->expectsOutputToContain('BACKUP_VERIFY_DB_DATABASE')->assertFailed();
});

it('prints the archive password with a warning', function () {
    $this->artisan('goproperty:backup-password')
        ->expectsOutputToContain('can read every tenant')
        ->expectsOutputToContain(app(ArchivePassword::class)->get())
        ->assertSuccessful();
});

it('reports each destination after a manual run', function () {
    $destination = s3Destination();
    Storage::fake($destination->diskName());

    $this->artisan('goproperty:backup-run', ['type' => 'db'])->expectsOutputToContain('success')->assertSuccessful();
    $this->artisan('goproperty:backup-run', ['type' => 'logs'])->assertExitCode(2);

    expect(BackupRun::query()->sole()->triggered_by)->toBe('manual');
});
