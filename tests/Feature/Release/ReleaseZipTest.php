<?php

declare(strict_types=1);

use App\Release\ReleaseZip;
use App\Release\VerificationReport;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\ReleaseFixture;

/*
 * P00-04: a release zip is checked in place, before anything is unpacked
 * (INSTALLER.md § 2). Both layouts must pass, and the zip must match its
 * .sha256 file and its own name.
 */

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/goproperty-zip-'.Str::random(12);
    File::ensureDirectoryExists($this->dir);
    $this->zip = $this->dir.'/goproperty-1.4.0.zip';
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

function verifyZip(string $path): VerificationReport
{
    return app(ReleaseZip::class)->verify($path);
}

/**
 * Rewrites one entry, as someone editing the zip after the build would.
 */
function replaceZipEntry(string $path, string $name, string $contents): void
{
    $zip = new ZipArchive;
    $zip->open($path);
    $zip->addFromString($name, $contents);
    $zip->close();
}

it('passes a valid release zip', function () {
    ReleaseFixture::zip($this->zip);

    $report = verifyZip($this->zip);

    expect($report->problems)->toBe([])
        ->and($report->notes)->toBe([])
        ->and($report->version)->toBe('1.4.0')
        ->and($report->files)->toBe(37);
});

it('notes a missing .sha256 file without failing', function () {
    ReleaseFixture::zip($this->zip, sidecar: false);

    $report = verifyZip($this->zip);

    expect($report->passed())->toBeTrue()
        ->and($report->notes)->toBe(['No goproperty-1.4.0.zip.sha256 next to the zip: compare the checksum with the one on the release page.']);
});

it('refuses a zip with a changed file', function () {
    ReleaseFixture::zip($this->zip, sidecar: false);
    replaceZipEntry($this->zip, 'split/public_html/robots.txt', "Disallow: /\n");

    expect(verifyZip($this->zip)->problems)
        ->toBe(['split: public/robots.txt does not match its SHA256SUMS checksum (changed after the build).']);
});

it('refuses a zip that does not match its .sha256 file', function () {
    ReleaseFixture::zip($this->zip);
    replaceZipEntry($this->zip, 'README.txt', "Changed\n");

    expect(verifyZip($this->zip)->problems)
        ->toBe(['goproperty-1.4.0.zip does not match goproperty-1.4.0.zip.sha256: the file is damaged or was changed.']);
});

it('refuses a zip with an added file', function () {
    ReleaseFixture::zip($this->zip, extra: ['preferred/goproperty/public/shell.php' => '<?php']);

    expect(verifyZip($this->zip)->problems)
        ->toBe(['preferred: public/shell.php is not listed in SHA256SUMS (added after the build).']);
});

it('refuses unsafe paths before unpacking', function (string $name) {
    ReleaseFixture::zip($this->zip, extra: [$name => '<?php']);

    $report = verifyZip($this->zip);

    expect($report->problems)->toHaveCount(1)
        ->and($report->problems[0])->toStartWith('Unsafe path in the zip: ');
})->with([
    'parent folder' => ['preferred/goproperty/../../evil.php'],
    'absolute' => ['/tmp/evil.php'],
    'backslash' => ['preferred\\goproperty\\evil.php'],
]);

it('refuses entries outside the two layouts', function () {
    ReleaseFixture::zip($this->zip, extra: ['goproperty/.env' => 'APP_KEY=']);

    expect(verifyZip($this->zip)->problems)->toBe(['goproperty/.env is outside the preferred/ and split/ layouts.']);
});

it('refuses symlinks in the zip', function () {
    ReleaseFixture::zip($this->zip, sidecar: false);

    $zip = new ZipArchive;
    $zip->open($this->zip);
    $zip->addFromString('preferred/goproperty/public/storage', '../storage/app/public');
    $zip->setExternalAttributesName('preferred/goproperty/public/storage', ZipArchive::OPSYS_UNIX, 0120777 << 16);
    $zip->close();

    expect(verifyZip($this->zip)->problems)->toBe(['preferred: public/storage is a symlink.']);
});

it('refuses files in storage or bootstrap/cache', function () {
    $files = ReleaseFixture::files();
    $files['storage/logs/laravel.log'] = 'log';
    $files['bootstrap/cache/config.php'] = '<?php return [];';
    ReleaseFixture::zip($this->zip, preferred: $files);

    expect(verifyZip($this->zip)->problems)->toBe([
        'preferred: bootstrap/cache/config.php: storage/ and bootstrap/cache/ must be empty in a package.',
        'preferred: storage/logs/laravel.log: storage/ and bootstrap/cache/ must be empty in a package.',
    ]);
});

it('refuses a VERSION that does not match the file name', function () {
    ReleaseFixture::zip($this->zip, preferred: ReleaseFixture::files('1.3.9'));

    expect(verifyZip($this->zip)->problems)->toBe(['preferred: VERSION says 1.3.9 but the file name says 1.4.0.']);
});

it('refuses a file name without a version', function () {
    $zip = $this->dir.'/release.zip';
    ReleaseFixture::zip($zip);

    $report = verifyZip($zip);

    expect($report->problems)->toBe(['release.zip: the file name must be goproperty-<version>.zip, as CI names it.'])
        ->and($report->version)->toBe('1.4.0');
});

it('refuses a zip missing a layout', function () {
    $zip = new ZipArchive;
    $zip->open($this->zip, ZipArchive::CREATE);
    $zip->addFromString('README.txt', 'GoProperty');

    foreach (ReleaseFixture::withSums(ReleaseFixture::files()) as $file => $contents) {
        $zip->addFromString('preferred/goproperty/'.$file, $contents);
    }

    $zip->close();

    expect(verifyZip($this->zip)->problems)->toBe(['The split layout is missing.']);
});

it('refuses a split layout that cannot find its public files', function () {
    $split = ReleaseFixture::split(ReleaseFixture::files());
    unset($split['bootstrap/public-path.php']);
    $split['public/index.php'] = "<?php\nrequire __DIR__.'/../goproperty-app/bootstrap/php-version.php';\n";

    ReleaseFixture::zip($this->zip, split: $split);

    expect(verifyZip($this->zip)->problems)->toBe([
        'split: bootstrap/public-path.php is missing.',
        'split: public_html/index.php does not set the public path.',
    ]);
});

it('refuses a preferred layout with the split public path', function () {
    $preferred = ReleaseFixture::files();
    $preferred['bootstrap/public-path.php'] = "<?php\nreturn '/elsewhere';\n";

    ReleaseFixture::zip($this->zip, preferred: $preferred);

    expect(verifyZip($this->zip)->problems)->toBe(['preferred: bootstrap/public-path.php belongs to the split layout only.']);
});

it('refuses a file that is not a zip', function () {
    file_put_contents($this->zip, 'not a zip');

    expect(verifyZip($this->zip)->problems)->toBe(['goproperty-1.4.0.zip is not a readable zip file.']);
});
