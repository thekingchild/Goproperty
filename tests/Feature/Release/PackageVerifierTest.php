<?php

declare(strict_types=1);

use App\Release\DirectoryTree;
use App\Release\PackageVerifier;
use App\Release\VerificationReport;
use App\Release\VerifyMode;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\ReleaseFixture;

/*
 * P00-04: the packaging rules from INSTALLER.md § 2, on an unpacked layout.
 * Each case changes one thing in a valid package and recomputes SHA256SUMS
 * where needed, so only the rule under test can fail.
 */

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/goproperty-release-'.Str::random(12);
    File::ensureDirectoryExists($this->dir);
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

/**
 * @param  array<string, string>  $files
 */
function verifyLayout(string $dir, array $files): VerificationReport
{
    ReleaseFixture::write($dir, $files);

    return app(PackageVerifier::class)->verify(new DirectoryTree($dir), VerifyMode::Installation);
}

function problemsContain(VerificationReport $report, string $text): bool
{
    foreach ($report->problems as $problem) {
        if (str_contains($problem, $text)) {
            return true;
        }
    }

    return false;
}

it('passes a valid layout', function () {
    $report = verifyLayout($this->dir, ReleaseFixture::files());

    expect($report->problems)->toBe([])
        ->and($report->version)->toBe('1.4.0')
        ->and($report->files)->toBe(18);
});

it('refuses a changed file', function () {
    ReleaseFixture::write($this->dir, ReleaseFixture::files());
    file_put_contents($this->dir.'/config/app.php', "<?php\nreturn ['debug' => true];\n");

    $report = app(PackageVerifier::class)->verify(new DirectoryTree($this->dir), VerifyMode::Installation);

    expect($report->problems)->toBe(['config/app.php does not match its SHA256SUMS checksum (changed after the build).']);
});

it('refuses an added file', function () {
    ReleaseFixture::write($this->dir, ReleaseFixture::files());
    file_put_contents($this->dir.'/public/shell.php', '<?php system($_GET["c"]);');

    $report = app(PackageVerifier::class)->verify(new DirectoryTree($this->dir), VerifyMode::Installation);

    expect($report->problems)->toBe(['public/shell.php is not listed in SHA256SUMS (added after the build).']);
});

it('refuses a missing file', function () {
    ReleaseFixture::write($this->dir, ReleaseFixture::files());
    unlink($this->dir.'/vendor/acme/lib/src/Lib.php');

    $report = app(PackageVerifier::class)->verify(new DirectoryTree($this->dir), VerifyMode::Installation);

    expect($report->problems)->toBe(['vendor/acme/lib/src/Lib.php is listed in SHA256SUMS but missing.']);
});

it('refuses symlinks', function () {
    ReleaseFixture::write($this->dir, ReleaseFixture::files());
    symlink('/etc', $this->dir.'/public/etc');
    symlink($this->dir.'/config/app.php', $this->dir.'/config/linked.php');

    $report = app(PackageVerifier::class)->verify(new DirectoryTree($this->dir), VerifyMode::Installation);

    expect($report->problems)->toBe(['config/linked.php is a symlink.', 'public/etc is a symlink.']);
});

it('refuses a SHA256SUMS line it cannot read', function () {
    $files = ReleaseFixture::withSums(ReleaseFixture::files());
    $files['SHA256SUMS'] .= "not-a-hash  config/app.php\n".str_repeat('a', 64)."  ../outside.php\n";

    expect(verifyLayout($this->dir, $files)->problems)->toBe([
        'SHA256SUMS line 19 is not "<sha256>  <path>" with a safe path.',
        'SHA256SUMS line 20 is not "<sha256>  <path>" with a safe path.',
    ]);
});

it('refuses a layout without SHA256SUMS or VERSION', function (string $missing) {
    ReleaseFixture::write($this->dir, ReleaseFixture::files());
    unlink($this->dir.'/'.$missing);

    $report = app(PackageVerifier::class)->verify(new DirectoryTree($this->dir), VerifyMode::Installation);

    expect(problemsContain($report, "{$missing} is missing."))->toBeTrue();
})->with(['SHA256SUMS', 'VERSION']);

it('refuses a folder that is not a package at all', function () {
    $files = ReleaseFixture::files();
    unset($files['VERSION']);
    ReleaseFixture::write($this->dir, $files);
    unlink($this->dir.'/SHA256SUMS');

    $report = app(PackageVerifier::class)->verify(new DirectoryTree($this->dir), VerifyMode::Installation);

    expect($report->problems)->toBe(['Neither VERSION nor SHA256SUMS exists: this is not an unpacked release package.']);
});

it('refuses a VERSION that is not a version number', function () {
    $files = ReleaseFixture::files();
    $files['VERSION'] = "main\n";

    $report = verifyLayout($this->dir, $files);

    expect($report->problems)->toBe(['VERSION does not hold a version number such as 1.4.0.'])
        ->and($report->version)->toBeNull();
});

it('refuses environment, credential, key and SQLite files', function (string $path, string $reason) {
    $files = ReleaseFixture::files();
    $files[$path] = 'contents';

    expect(verifyLayout($this->dir, $files)->problems)->toBe(["{$path} is {$reason}."]);
})->with([
    'env file in public' => ['public/.env', 'an environment file'],
    'env copy' => ['config/.env.production', 'an environment file'],
    'composer credentials' => ['auth.json', 'a Composer credentials file'],
    'private key' => ['storage-keys/oauth-private.key', 'a key file'],
    'ssh key' => ['config/id_ed25519', 'a key file'],
    'certificate bundle' => ['config/client.p12', 'a key file'],
    'sqlite database' => ['database/database.sqlite', 'an SQLite database'],
    'sqlite journal' => ['database/app.db-journal', 'an SQLite database'],
    'git folder' => ['vendor/acme/lib/.git/config', 'inside a development folder (.git or node_modules)'],
    'node modules' => ['node_modules/vite/index.js', 'inside a development folder (.git or node_modules)'],
]);

it('allows env example files', function () {
    $files = ReleaseFixture::files();
    $files['vendor/acme/lib/.env.example'] = 'KEY=';

    expect(verifyLayout($this->dir, $files)->problems)->toBe([]);
});

it('refuses an SQLite database by its contents', function () {
    $files = ReleaseFixture::files();
    $files['database/seed.bin'] = "SQLite format 3\0".str_repeat("\0", 84);

    expect(verifyLayout($this->dir, $files)->problems)->toBe(['database/seed.bin is an SQLite database.']);
});

it('refuses secret-shaped strings without printing them', function (string $secret, string $kind) {
    $files = ReleaseFixture::files();
    $files['config/services.php'] = "<?php\nreturn ['key' => '{$secret}'];\n";

    $report = verifyLayout($this->dir, $files);

    expect($report->problems)->toBe(["config/services.php contains a secret-shaped string ({$kind})."])
        ->and(implode("\n", $report->problems))->not->toContain($secret);
})->with([
    // Built from pieces so the repository never holds a whole token.
    'private key' => ["-----BEGIN RSA PRIVATE KEY-----\n".str_repeat('MIIEow', 10), 'private key'],
    'private key in json' => ['-----BEGIN PRIVATE KEY-----\\n'.str_repeat('MIIEvQ', 10), 'private key'],
    'aws access key' => ['AKIA'.'Q3EGRTLPZ7XWN4VD', 'aws access key'],
    'paystack live secret' => ['sk_'.'live_'.str_repeat('a1b2', 10), 'payment secret key'],
    'paystack test secret' => ['sk_'.'test_'.str_repeat('a1b2', 10), 'payment secret key'],
    'github token' => ['gh'.'p_'.str_repeat('Ab3d', 9), 'github token'],
    'slack token' => ['xo'.'xb-'.'1234567890-abcdefghij', 'slack token'],
    'laravel app key' => ['APP_KEY=base64:'.base64_encode(str_repeat('k', 32)), 'laravel app key'],
    'google api key' => ['AI'.'za'.str_repeat('Sy8', 11).'Ab', 'google api key'],
    'dsn with key' => ['https://'.str_repeat('0f', 16).'@o123.ingest.sentry.io/456', 'dsn with key'],
]);

it('does not mistake documented examples and public keys for secrets', function () {
    $files = ReleaseFixture::files();
    $files['config/services.php'] = "<?php\n// e.g. AKIAIOSFODNN7EXAMPLE, pk_live_ keys are public\nreturn ['paystack' => env('PAYSTACK_SECRET_KEY'), 'app' => 'APP_KEY='];\n";
    $files['vendor/acme/lib/docs.md'] = "-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----\n";

    expect(verifyLayout($this->dir, $files)->problems)->toBe([]);
});

it('refuses dev dependencies', function () {
    $files = ReleaseFixture::files();
    $files['vendor/pestphp/pest/bin/pest'] = '#!/usr/bin/env php';

    expect(verifyLayout($this->dir, $files)->problems)->toBe(['Dev dependency pestphp/pest is in vendor/.']);
});

it('refuses a vendor folder installed with dev dependencies', function () {
    $files = ReleaseFixture::files();
    $files['vendor/composer/installed.json'] = json_encode(['packages' => [], 'dev' => true, 'dev-package-names' => ['pestphp/pest']], JSON_THROW_ON_ERROR);

    expect(verifyLayout($this->dir, $files)->problems)->toBe(['vendor/ was installed with dev dependencies (build with composer install --no-dev).']);
});

it('refuses a package without vendor', function () {
    $files = array_filter(ReleaseFixture::files(), fn (string $path): bool => ! str_starts_with($path, 'vendor/'), ARRAY_FILTER_USE_KEY);

    expect(verifyLayout($this->dir, $files)->problems)->toBe(['vendor/ is missing: the package must include the production dependencies.']);
});

it('refuses a missing Vite manifest', function () {
    $files = ReleaseFixture::files();
    unset($files['public/build/manifest.json']);

    expect(verifyLayout($this->dir, $files)->problems)->toBe(['The Vite manifest (public/build/manifest.json) is missing: build the front-end assets.']);
});

it('refuses a Vite manifest whose assets are missing', function () {
    $files = ReleaseFixture::files();
    unset($files['public/build/assets/app-1a2b.css'], $files['public/build/assets/inter-400.woff2']);

    expect(verifyLayout($this->dir, $files)->problems)->toBe([
        'public/build/assets/app-1a2b.css is in the Vite manifest but missing.',
        'public/build/assets/inter-400.woff2 is in the Vite manifest but missing.',
    ]);
});

it('refuses entry points that skip the old-PHP guard', function () {
    $files = ReleaseFixture::files();
    $files['public/index.php'] = "<?php\nrequire __DIR__.'/../vendor/autoload.php';\n";

    expect(verifyLayout($this->dir, $files)->problems)->toBe(['public/index.php does not load the old-PHP guard (bootstrap/php-version.php).']);
});

it('ignores the installation’s own environment, storage and caches', function () {
    ReleaseFixture::write($this->dir, ReleaseFixture::files());
    file_put_contents($this->dir.'/.env', 'APP_KEY=base64:'.base64_encode(str_repeat('k', 32)));
    file_put_contents($this->dir.'/.env.tmp-1234', 'DB_PASSWORD=secret');
    File::ensureDirectoryExists($this->dir.'/storage/app/private');
    file_put_contents($this->dir.'/storage/app/installed.lock', '1');
    file_put_contents($this->dir.'/storage/logs/laravel.log', 'APP_KEY=base64:'.base64_encode(str_repeat('k', 32)));
    file_put_contents($this->dir.'/bootstrap/cache/config.php', '<?php return [];');

    $report = app(PackageVerifier::class)->verify(new DirectoryTree($this->dir), VerifyMode::Installation);

    expect($report->problems)->toBe([]);
});

it('ignores a storage folder linked in by the SSH deploy', function () {
    ReleaseFixture::write($this->dir, ReleaseFixture::files());
    File::deleteDirectory($this->dir.'/storage');
    File::ensureDirectoryExists($this->dir.'-shared/storage');
    symlink($this->dir.'-shared/storage', $this->dir.'/storage');
    symlink($this->dir.'-shared/.env', $this->dir.'/.env');

    try {
        $report = app(PackageVerifier::class)->verify(new DirectoryTree($this->dir), VerifyMode::Installation);
    } finally {
        File::deleteDirectory($this->dir.'-shared');
    }

    expect($report->problems)->toBe([]);
});

it('tolerates files that hosting panels add', function () {
    ReleaseFixture::write($this->dir, ReleaseFixture::files());
    File::ensureDirectoryExists($this->dir.'/public/.well-known/acme-challenge');
    File::ensureDirectoryExists($this->dir.'/public/cgi-bin');
    file_put_contents($this->dir.'/public/.well-known/acme-challenge/token', 'abc');
    file_put_contents($this->dir.'/public/cgi-bin/.keep', '');
    file_put_contents($this->dir.'/public/error_log', 'PHP Warning: ...');
    file_put_contents($this->dir.'/public/.user.ini', 'memory_limit=512M');
    file_put_contents($this->dir.'/error_log', 'PHP Warning: ...');

    // cPanel's MultiPHP Manager adds its handler block to the document root's .htaccess.
    $htaccess = file_get_contents($this->dir.'/public/.htaccess');
    file_put_contents($this->dir.'/public/.htaccess', $htaccess
        ."\n# php -- BEGIN cPanel-generated handler, do not edit\n"
        ."<IfModule mime_module>\n  AddHandler application/x-httpd-ea-php84 .php .php8 .phtml\n</IfModule>\n"
        ."# php -- END cPanel-generated handler, do not edit\n");

    $report = app(PackageVerifier::class)->verify(new DirectoryTree($this->dir), VerifyMode::Installation);

    expect($report->problems)->toBe([]);
});

it('still refuses other changes to .htaccess', function () {
    ReleaseFixture::write($this->dir, ReleaseFixture::files());
    file_put_contents($this->dir.'/public/.htaccess', "Options +Indexes\n", FILE_APPEND);

    $report = app(PackageVerifier::class)->verify(new DirectoryTree($this->dir), VerifyMode::Installation);

    expect($report->problems)->toBe(['public/.htaccess does not match its SHA256SUMS checksum (changed after the build).']);
});

it('reads the split layout’s public files from public_html', function () {
    $app = $this->dir.'/goproperty-app';
    ReleaseFixture::write($app, ReleaseFixture::split(ReleaseFixture::files()), $this->dir.'/public_html');

    $tree = DirectoryTree::forLayout($app);
    $report = app(PackageVerifier::class)->verify($tree, VerifyMode::Installation);

    expect($report->problems)->toBe([])
        ->and($tree->entries())->toHaveKey('public/build/manifest.json')
        ->and($tree->describe())->toContain('public files in '.$this->dir.'/public_html');

    file_put_contents($this->dir.'/public_html/robots.txt', "Disallow: /\n");

    expect(app(PackageVerifier::class)->verify(DirectoryTree::forLayout($app), VerifyMode::Installation)->problems)
        ->toBe(['public/robots.txt does not match its SHA256SUMS checksum (changed after the build).']);
});

it('accepts only plain relative paths', function (string $path, bool $safe) {
    expect(PackageVerifier::isSafePath($path))->toBe($safe);
})->with([
    ['app/Models/User.php', true],
    ['public/.htaccess', true],
    ['../etc/passwd', false],
    ['app/../../x', false],
    ['/etc/passwd', false],
    ['C:/Windows/x', false],
    ['app\\x.php', false],
    ['app//x.php', false],
    ['./app/x.php', false],
    ["app/x\n.php", false],
    ['', false],
]);
