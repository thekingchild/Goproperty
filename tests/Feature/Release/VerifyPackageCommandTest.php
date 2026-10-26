<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\ReleaseFixture;

/*
 * P00-04 acceptance, at command level: `goproperty:verify-package` passes a
 * release and fails a tampered one (INSTALLER.md § 2).
 */

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/goproperty-command-'.Str::random(12);
    File::ensureDirectoryExists($this->dir);
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

it('passes a release zip', function () {
    ReleaseFixture::zip($this->dir.'/goproperty-1.4.0.zip');

    $this->artisan('goproperty:verify-package', ['path' => $this->dir.'/goproperty-1.4.0.zip'])
        ->expectsOutputToContain('Package verified')
        ->assertSuccessful();
});

it('fails a tampered release zip', function () {
    $zip = $this->dir.'/goproperty-1.4.0.zip';
    ReleaseFixture::zip($zip, sidecar: false);

    $archive = new ZipArchive;
    $archive->open($zip);
    $archive->addFromString('preferred/goproperty/config/app.php', "<?php\nreturn ['debug' => true];\n");
    $archive->close();

    $this->artisan('goproperty:verify-package', ['path' => $zip])
        ->expectsOutputToContain('Verification failed with 1 problem.')
        ->expectsOutputToContain('preferred: config/app.php does not match its SHA256SUMS checksum')
        ->assertFailed();
});

it('passes and fails an unpacked split layout', function () {
    $app = $this->dir.'/split/goproperty-app';
    ReleaseFixture::write($app, ReleaseFixture::split(ReleaseFixture::files()), $this->dir.'/split/public_html');

    $this->artisan('goproperty:verify-package', ['path' => $app])
        ->expectsOutputToContain('Package verified')
        ->assertSuccessful();

    file_put_contents($this->dir.'/split/public_html/build/assets/app-3c4d.js', 'alert(1)');

    $this->artisan('goproperty:verify-package', ['path' => $app])
        ->expectsOutputToContain('public/build/assets/app-3c4d.js does not match its SHA256SUMS checksum')
        ->assertFailed();
});

it('takes the split public folder as an option', function () {
    $app = $this->dir.'/goproperty-app';
    ReleaseFixture::write($app, ReleaseFixture::split(ReleaseFixture::files()), $this->dir.'/httpdocs');

    $this->artisan('goproperty:verify-package', ['path' => $app, '--public' => $this->dir.'/httpdocs'])
        ->assertSuccessful();
});

it('checks this installation when given no path', function () {
    // A development checkout is not a package: it has no VERSION or SHA256SUMS.
    $this->artisan('goproperty:verify-package')
        ->expectsOutputToContain('Neither VERSION nor SHA256SUMS exists: this is not an unpacked release package')
        ->assertFailed();
});

it('lists at most 50 problems', function () {
    $files = ReleaseFixture::files();
    ReleaseFixture::write($this->dir, $files);

    foreach (range(1, 60) as $number) {
        file_put_contents($this->dir."/public/added-{$number}.txt", 'x');
    }

    $this->artisan('goproperty:verify-package', ['path' => $this->dir])
        ->expectsOutputToContain('Verification failed with 60 problems.')
        ->expectsOutputToContain('…and 10 more.')
        ->assertFailed();
});
