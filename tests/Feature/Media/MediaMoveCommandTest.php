<?php

declare(strict_types=1);

use App\Installer\EnvironmentFile;
use App\Media\Media;
use App\Media\MediaDiskMove;
use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

require_once __DIR__.'/../../Support/media.php';

/*
 * The product owner (2026-11-09): uploads stay on the host's disk by
 * default, and moving them to a bucket later must be supported.
 * `goproperty:media-move` checks the target, points new uploads at it
 * (MEDIA_DISK in .env), then copies each file's folder, checks the copies
 * and repoints the row; it can run again until nothing is left, and back.
 */

beforeEach(function () {
    Storage::fake('media');
    Storage::fake('media-s3');
    config(['filesystems.disks.media-s3.bucket' => 'goproperty-files', 'media-library.disk_name' => 'media']);

    $this->env = sys_get_temp_dir().'/gp-media-move-'.bin2hex(random_bytes(4)).'.env';
    file_put_contents($this->env, "APP_NAME=GoProperty\n");
    app()->instance(EnvironmentFile::class, new EnvironmentFile($this->env));

    $this->acme = Organization::factory()->create(['slug' => 'acme-homes']);
    $this->other = Organization::factory()->create(['slug' => 'other-realty']);
});

afterEach(function () {
    app(OrganizationContext::class)->clear();
    @unlink($this->env);
});

function mediaRow(Media $media): object
{
    return DB::table('media')->where('id', $media->id)->first();
}

function mediaFolder(Media $media): string
{
    return $media->organization_id.'/'.$media->uuid;
}

it('moves every file with its conversions to the bucket, repoints the rows and keeps new uploads there', function () {
    $photo = uploadMedia($this->acme, mediaImage('lobby.jpg', 800, 600));
    $proof = uploadMedia($this->other, mediaPdf('proof.pdf'), 'payment_proofs');
    $files = Storage::disk('media')->allFiles(mediaFolder($photo));
    expect(count($files))->toBeGreaterThan(1); // the original and its conversions

    $this->travel(MediaDiskMove::SETTLE_MINUTES + 1)->minutes();

    $this->artisan('goproperty:media-move', ['--force' => true])
        ->expectsOutputToContain('MEDIA_DISK=media-s3 is set in .env')
        ->expectsOutputToContain('Every file that can move has moved.')
        ->expectsOutputToContain('not in the nightly files backup')
        ->assertSuccessful();

    foreach ($files as $path) {
        expect(Storage::disk('media-s3')->get($path))->toBe(Storage::disk('media')->get($path));
    }

    expect(mediaRow($photo)->disk)->toBe('media-s3')
        ->and(mediaRow($photo)->conversions_disk)->toBe('media-s3')
        ->and(mediaRow($proof)->disk)->toBe('media-s3')
        ->and(Storage::disk('media-s3')->exists(mediaFolder($proof).'/'.$proof->file_name))->toBeTrue()
        // The old copies stay until --delete-source.
        ->and(Storage::disk('media')->exists(mediaFolder($photo).'/'.$photo->file_name))->toBeTrue()
        ->and((string) file_get_contents($this->env))->toContain('MEDIA_DISK=media-s3')
        ->and(config('media-library.disk_name'))->toBe('media-s3');

    // Served from the bucket through the signed route, conversions too.
    Storage::disk('media')->deleteDirectory(mediaFolder($photo));
    $url = URL::temporarySignedRoute('media.show', now()->addMinutes(15), ['organization' => $this->acme->id, 'media' => $photo->uuid]);
    expect($this->get($url)->assertOk()->streamedContent())->toBe(Storage::disk('media-s3')->get(mediaFolder($photo).'/'.$photo->file_name));
    $thumb = URL::temporarySignedRoute('media.show', now()->addMinutes(15), ['organization' => $this->acme->id, 'media' => $photo->uuid, 'conversion' => 'thumb']);
    $this->get($thumb)->assertOk();

    // A new upload lands in the bucket.
    $next = uploadMedia($this->acme, mediaImage('garden.jpg'));
    expect(mediaRow($next)->disk)->toBe('media-s3');
});

it('leaves files changed in the last minutes for the next run, and carries on there', function () {
    $old = uploadMedia($this->acme, mediaImage('old.jpg'));
    $this->travel(MediaDiskMove::SETTLE_MINUTES + 1)->minutes();
    $recent = uploadMedia($this->acme, mediaImage('recent.jpg'));

    $this->artisan('goproperty:media-move', ['--force' => true])
        ->expectsOutputToContain('1 files are still to move: run this again in 10 minutes.')
        ->assertSuccessful();

    expect(mediaRow($old)->disk)->toBe('media-s3')
        ->and(mediaRow($recent)->disk)->toBe('media');

    $this->travel(MediaDiskMove::SETTLE_MINUTES + 1)->minutes();
    $this->artisan('goproperty:media-move', ['--force' => true])
        ->expectsOutputToContain('Every file that can move has moved.')
        ->assertSuccessful();

    expect(mediaRow($recent)->disk)->toBe('media-s3');
});

it('leaves a row whose file is missing where it is, and says so', function () {
    $photo = uploadMedia($this->acme, mediaImage('gone.jpg'));
    Storage::disk('media')->deleteDirectory(mediaFolder($photo));
    $this->travel(MediaDiskMove::SETTLE_MINUTES + 1)->minutes();

    $this->artisan('goproperty:media-move', ['--force' => true])
        ->expectsOutputToContain("File {$photo->id} is missing on the media disk")
        ->assertSuccessful();

    expect(mediaRow($photo)->disk)->toBe('media');
});

it('removes the old copies with --delete-source, those of earlier runs too', function () {
    $first = uploadMedia($this->acme, mediaImage('first.jpg'));
    $this->travel(MediaDiskMove::SETTLE_MINUTES + 1)->minutes();
    $this->artisan('goproperty:media-move', ['--force' => true])->assertSuccessful();
    expect(Storage::disk('media')->exists(mediaFolder($first).'/'.$first->file_name))->toBeTrue();

    $second = uploadMedia($this->acme, mediaImage('second.jpg'));
    DB::table('media')->where('id', $second->id)->update(['disk' => 'media', 'conversions_disk' => 'media']);
    Storage::disk('media')->put(mediaFolder($second).'/'.$second->file_name, (string) Storage::disk('media-s3')->get(mediaFolder($second).'/'.$second->file_name));
    $this->travel(MediaDiskMove::SETTLE_MINUTES + 1)->minutes();

    $this->artisan('goproperty:media-move', ['--force' => true, '--delete-source' => true])
        ->expectsOutputToContain('Old copies removed from earlier runs')
        ->assertSuccessful();

    expect(Storage::disk('media')->allFiles())->toBe([])
        ->and(mediaRow($second)->disk)->toBe('media-s3')
        ->and(Storage::disk('media-s3')->exists(mediaFolder($first).'/'.$first->file_name))->toBeTrue()
        ->and(Storage::disk('media-s3')->exists(mediaFolder($second).'/'.$second->file_name))->toBeTrue();
});

it('moves the files back to this host', function () {
    $photo = uploadMedia($this->acme, mediaImage('lobby.jpg'));
    $this->travel(MediaDiskMove::SETTLE_MINUTES + 1)->minutes();
    $this->artisan('goproperty:media-move', ['--force' => true, '--delete-source' => true])->assertSuccessful();
    expect(Storage::disk('media')->allFiles())->toBe([]);

    $this->artisan('goproperty:media-move', ['disk' => 'media', '--force' => true])
        ->expectsOutputToContain('MEDIA_DISK=media is set in .env')
        ->doesntExpectOutputToContain('nightly files backup')
        ->assertSuccessful();

    expect(mediaRow($photo)->disk)->toBe('media')
        ->and(Storage::disk('media')->exists(mediaFolder($photo).'/'.$photo->file_name))->toBeTrue()
        ->and((string) file_get_contents($this->env))->toContain('MEDIA_DISK=media')
        ->and((string) file_get_contents($this->env))->not->toContain('MEDIA_DISK=media-s3');
});

it('changes nothing without a bucket, without --force when it cannot ask, or while another move runs', function () {
    $photo = uploadMedia($this->acme, mediaImage('lobby.jpg'));
    $this->travel(MediaDiskMove::SETTLE_MINUTES + 1)->minutes();

    config(['filesystems.disks.media-s3.bucket' => '']);
    $this->artisan('goproperty:media-move', ['--force' => true])
        ->expectsOutputToContain('No bucket is set')
        ->assertFailed();

    config(['filesystems.disks.media-s3.bucket' => 'goproperty-files']);
    $this->artisan('goproperty:media-move', ['--no-interaction' => true])
        ->expectsOutputToContain('Pass --force')
        ->assertFailed();

    $this->artisan('goproperty:media-move', ['disk' => 'public', '--force' => true])->assertFailed();

    $lock = Cache::lock('goproperty:media-move', 60);
    $lock->get();
    $this->artisan('goproperty:media-move', ['--force' => true])
        ->expectsOutputToContain('Another move is running')
        ->assertFailed();
    $lock->release();

    expect(mediaRow($photo)->disk)->toBe('media')
        ->and((string) file_get_contents($this->env))->not->toContain('MEDIA_DISK')
        ->and(Storage::disk('media-s3')->allFiles())->toBe([]);
});

it('asks first when it can', function () {
    uploadMedia($this->acme, mediaImage('lobby.jpg'));

    $this->artisan('goproperty:media-move')
        ->expectsConfirmation('Move uploaded files to the bucket and keep new uploads there?', 'no')
        ->expectsOutputToContain('Nothing was changed.')
        ->assertFailed();

    expect((string) file_get_contents($this->env))->not->toContain('MEDIA_DISK');
});
