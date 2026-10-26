<?php

declare(strict_types=1);

use App\Backup\ArchivePassword;
use App\Backup\BackupDestination;
use App\Backup\BackupRun;
use App\Backup\BackupRunner;
use App\Backup\Restorer;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\FakeGoogleDrive;

/*
 * P00-12b: the Google Drive destination (service account + Shared Drive,
 * PLATFORM_CONSOLE.md § 4), the files backup, and the destination commands.
 */

function driveDestination(array $account, string $for = 'both'): BackupDestination
{
    return BackupDestination::query()->create([
        'name' => 'Workspace Shared Drive',
        'driver' => 'google_drive',
        'config' => ['service_account' => $account, 'shared_drive_id' => FakeGoogleDrive::DRIVE, 'folder_id' => null],
        'applies_to' => $for,
    ]);
}

function driveDisk(BackupDestination $destination, array $extra = []): Filesystem
{
    config(['filesystems.disks.'.$destination->diskName() => $destination->diskConfig() + $extra]);
    Storage::forgetDisk($destination->diskName());

    return Storage::disk($destination->diskName());
}

it('signs in with the service account and keeps files in folders on the Shared Drive', function () {
    $drive = FakeGoogleDrive::install();
    ['account' => $account, 'public' => $public] = FakeGoogleDrive::serviceAccount();
    $disk = driveDisk(driveDestination($account));

    $disk->put('goproperty-db/2026-10-26-01-30-00.zip', 'first');
    $disk->put('goproperty-db/2026-10-27-01-30-00.zip', 'second');
    $disk->put('goproperty-db/2026-10-27-01-30-00.zip', 'second, again');

    expect($drive->paths())->toBe(['goproperty-db/2026-10-26-01-30-00.zip', 'goproperty-db/2026-10-27-01-30-00.zip'])
        ->and($disk->get('goproperty-db/2026-10-27-01-30-00.zip'))->toBe('second, again')
        ->and($disk->exists('goproperty-db/2026-10-26-01-30-00.zip'))->toBeTrue()
        ->and($disk->exists('goproperty-db/missing.zip'))->toBeFalse()
        ->and($disk->allFiles('goproperty-db'))->toHaveCount(2)
        ->and($disk->size('goproperty-db/2026-10-26-01-30-00.zip'))->toBe(5)
        ->and($disk->lastModified('goproperty-db/2026-10-26-01-30-00.zip'))->toBeGreaterThan(now()->subMinute()->getTimestamp());

    $disk->delete('goproperty-db/2026-10-26-01-30-00.zip');
    expect($drive->paths())->toBe(['goproperty-db/2026-10-27-01-30-00.zip']);

    // One folder, one token, and a JWT signed by the service account's key.
    expect(collect($drive->files)->where('mimeType', 'application/vnd.google-apps.folder')->count())->toBe(1)
        ->and($drive->assertions)->toHaveCount(1);

    [$header, $claims, $signature] = explode('.', $drive->assertions[0]);
    $claims = json_decode(base64_decode(strtr($claims, '-_', '+/')), true);
    expect(openssl_verify($header.'.'.explode('.', $drive->assertions[0])[1], base64_decode(strtr($signature, '-_', '+/')), $public, OPENSSL_ALGO_SHA256))->toBe(1)
        ->and($claims)->toMatchArray(['iss' => 'backups@goproperty-test.iam.gserviceaccount.com', 'scope' => 'https://www.googleapis.com/auth/drive', 'aud' => 'https://oauth2.googleapis.com/token']);
});

it('uploads large files in resumable chunks', function () {
    $drive = FakeGoogleDrive::install();
    $disk = driveDisk(driveDestination(FakeGoogleDrive::serviceAccount()['account']), ['chunk_size' => 256 * 1024]);
    $content = random_bytes(700 * 1024);

    $disk->put('goproperty-files/big.zip', $content);

    expect($drive->uploadRequests)->toBe(3)
        ->and($drive->contentOf('goproperty-files/big.zip') === $content)->toBeTrue();
});

it('backs the database up to Google Drive and downloads it again', function () {
    $drive = FakeGoogleDrive::install();
    $destination = driveDestination(FakeGoogleDrive::serviceAccount()['account']);

    $run = app(BackupRunner::class)->run('db')->sole();

    expect($run->status)->toBe('success', (string) $run->notes)
        ->and($drive->paths())->toHaveCount(1)
        ->and($drive->paths()[0])->toStartWith('goproperty-db/')
        ->and($run->filename)->toBe($drive->paths()[0]);

    $work = sys_get_temp_dir().'/gp-drive-'.Str::random(6);
    File::ensureDirectoryExists($work);
    $download = app(Restorer::class)->downloadNewest($destination, $work);
    $sql = app(Restorer::class)->extractDump($download['path'], $work);
    expect((string) file_get_contents($sql))->toContain('CREATE TABLE');
    File::deleteDirectory($work);
});

it('records a Drive refusal as a failed run', function () {
    $drive = FakeGoogleDrive::install();
    driveDestination(FakeGoogleDrive::serviceAccount()['account']);
    $drive->failWith = 403;

    $run = app(BackupRunner::class)->run('db')->sole();

    expect($run->status)->toBe('failed')
        ->and($run->notes)->toContain('sufficient permissions');
});

it('backs up uploaded files as their own encrypted job', function () {
    $folder = sys_get_temp_dir().'/gp-files-'.Str::random(6);
    $marker = 'brand/drill-'.Str::random(6).'.txt';
    File::ensureDirectoryExists(storage_path('app/brand'));
    file_put_contents(storage_path('app/'.$marker), 'brand file');
    BackupDestination::query()->create(['name' => 'Drill', 'driver' => 'local', 'config' => ['root' => $folder], 'applies_to' => 'files']);

    $event = collect(app(Schedule::class)->events())->first(fn ($event) => $event->description === 'backup-files');
    $event->run(app());

    $run = BackupRun::query()->sole();
    $zips = glob($folder.'/goproperty-files/*.zip') ?: [];
    expect($run->type)->toBe('files')
        ->and($run->status)->toBe('success')
        ->and($zips)->toHaveCount(1);

    $zip = new ZipArchive;
    $zip->open($zips[0]);
    expect($zip->locateName($marker))->not->toBeFalse()
        ->and($zip->locateName('db-dumps/', ZipArchive::FL_NODIR))->toBeFalse()
        ->and($zip->statName($marker)['encryption_method'])->toBe(ZipArchive::EM_AES_256)
        ->and($zip->getFromName($marker))->toBeFalse();
    $zip->setPassword(app(ArchivePassword::class)->get());
    expect($zip->getFromName($marker))->toBe('brand file');
    $zip->close();

    @unlink(storage_path('app/'.$marker));
    File::deleteDirectory($folder);
});

it('adds, lists, checks and switches off destinations from the command line', function () {
    $drive = FakeGoogleDrive::install();
    $dir = sys_get_temp_dir().'/gp-cli-'.Str::random(6);
    File::ensureDirectoryExists($dir);
    file_put_contents($dir.'/secret', "s3-secret-value\n");
    file_put_contents($dir.'/account.json', json_encode(FakeGoogleDrive::serviceAccount()['account']));

    $this->artisan('goproperty:backup-destination:add', ['name' => 'R2', 'driver' => 's3', '--bucket' => 'gp-backups', '--key' => 'AKIA1', '--secret-file' => $dir.'/secret', '--endpoint' => 'https://acct.r2.cloudflarestorage.com', '--for' => 'db', '--no-check' => true])
        ->expectsOutputToContain('saved')
        ->assertSuccessful();

    $this->artisan('goproperty:backup-destination:add', ['name' => 'Drive', 'driver' => 'google_drive', '--service-account-file' => $dir.'/account.json', '--shared-drive-id' => FakeGoogleDrive::DRIVE])
        ->expectsOutputToContain('saved and checked')
        ->assertSuccessful();

    $s3 = BackupDestination::query()->where('name', 'R2')->sole();
    expect($s3->config)->toMatchArray(['secret' => 's3-secret-value', 'bucket' => 'gp-backups', 'region' => 'auto'])
        ->and($s3->applies_to)->toBe('db')
        ->and($drive->paths())->toBe([]);

    $this->artisan('goproperty:backup-destination:list')->expectsOutputToContain('google_drive')->assertSuccessful();

    $driveDestination = BackupDestination::query()->where('name', 'Drive')->sole();
    $this->artisan('goproperty:backup-destination:test', ['id' => $driveDestination->id])->expectsOutputToContain('works')->assertSuccessful();
    $this->artisan('goproperty:backup-destination:test', ['id' => $driveDestination->id, '--disable' => true])->assertSuccessful();
    expect($driveDestination->fresh()->active)->toBeFalse();

    $drive->failWith = 403;
    $this->artisan('goproperty:backup-destination:add', ['name' => 'No access', 'driver' => 'google_drive', '--service-account-file' => $dir.'/account.json', '--shared-drive-id' => FakeGoogleDrive::DRIVE])
        ->expectsOutputToContain('switched off')
        ->assertFailed();
    expect(BackupDestination::query()->where('name', 'No access')->sole()->active)->toBeFalse();

    $this->artisan('goproperty:backup-destination:add', ['name' => 'Bad', 'driver' => 'google_drive', '--service-account-file' => $dir.'/secret', '--shared-drive-id' => 'x'])->assertExitCode(2);

    File::deleteDirectory($dir);
});
