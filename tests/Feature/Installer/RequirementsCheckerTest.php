<?php

declare(strict_types=1);

use App\Installer\Requirements\CheckStatus;
use App\Installer\Requirements\RequirementCheck;
use App\Installer\Requirements\RequirementsChecker;
use App\Installer\Requirements\RequirementsReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\FakeServer;

/*
 * P00-05: the installer's server check (INSTALLER.md § 5, step 2), against
 * a simulated host so every failure can be produced.
 */

/**
 * @param  array<string, mixed>  $server
 */
function checkServer(array $server = [], ?Request $request = null): RequirementsReport
{
    return (new RequirementsChecker(FakeServer::make($server)))->check($request ?? Request::create('https://goproperty.ng/install', 'GET', server: [
        'DOCUMENT_ROOT' => public_path(),
    ]));
}

function findCheck(RequirementsReport $report, string $label): RequirementCheck
{
    foreach ($report->checks as $check) {
        if ($check->label === $label) {
            return $check;
        }
    }

    throw new RuntimeException("No check labelled {$label}.");
}

it('passes a good host', function () {
    $report = checkServer();

    expect($report->passed())->toBeTrue()
        ->and($report->failures())->toBe([])
        ->and($report->publicSummary())->toBe(['PHP' => true, 'Folders' => true, 'Security' => true, 'Build' => true]);
});

it('blocks an old PHP with cPanel and Plesk advice', function () {
    $report = checkServer(['php' => '8.3.14']);
    $check = findCheck($report, 'PHP 8.4 or newer');

    expect($report->passed())->toBeFalse()
        ->and($check->status)->toBe(CheckStatus::Fail)
        ->and($check->detail)->toBe('This site runs PHP 8.3.14.')
        ->and($check->cpanel)->toContain('MultiPHP Manager')
        ->and($check->plesk)->toContain('PHP Settings')
        ->and($report->publicSummary()['PHP'])->toBeFalse();
});

it('blocks a missing extension', function (string $extension) {
    $report = checkServer(['missing' => [$extension]]);

    expect(findCheck($report, "PHP extension: {$extension}")->blocks())->toBeTrue()
        ->and($report->passed())->toBeFalse();
})->with(RequirementsChecker::EXTENSIONS);

it('needs gd or imagick, not both', function (array $missing, bool $passes) {
    expect(findCheck(checkServer(['missing' => $missing]), 'PHP extension: gd or imagick')->blocks())->toBe(! $passes);
})->with([
    'gd only' => [['imagick'], true],
    'imagick only' => [['gd'], true],
    'neither' => [['gd', 'imagick'], false],
]);

it('needs at least 30 seconds of execution time', function (string $seconds, bool $passes) {
    expect(findCheck(checkServer(['ini' => ['max_execution_time' => $seconds]]), 'max_execution_time of 30 seconds or more')->blocks())->toBe(! $passes);
})->with([['0', true], ['30', true], ['300', true], ['29', false], ['10', false]]);

it('only warns about advisory settings', function () {
    $report = checkServer([
        'ini' => ['memory_limit' => '128M', 'upload_max_filesize' => '2M'],
        'disabled' => ['exec', 'proc_open'],
        'disk' => 1.0 * 1024 ** 3,
        'opcache' => false,
    ]);

    foreach (['memory_limit of 256M or more', 'upload_max_filesize of 20M or more', 'proc_open and exec (optional)', '2 GB of free disk space', 'OPcache'] as $label) {
        expect(findCheck($report, $label)->status)->toBe(CheckStatus::Warn);
    }

    expect($report->passed())->toBeTrue();
});

it('reads unlimited memory as enough', function () {
    expect(findCheck(checkServer(['ini' => ['memory_limit' => '-1']]), 'memory_limit of 256M or more')->status)->toBe(CheckStatus::Pass);
});

it('warns when the host hides free disk space', function () {
    expect(findCheck(checkServer(['disk' => null]), '2 GB of free disk space')->detail)->toBe('The host does not report free space.');
});

it('needs HTTPS except on a local test copy', function (string $url, CheckStatus $status) {
    $report = checkServer(request: Request::create($url, 'GET', server: ['DOCUMENT_ROOT' => public_path()]));

    expect(findCheck($report, 'HTTPS')->status)->toBe($status);
})->with([
    'https' => ['https://goproperty.ng/install', CheckStatus::Pass],
    'plain http' => ['http://goproperty.ng/install', CheckStatus::Fail],
    'localhost' => ['http://localhost/install', CheckStatus::Warn],
    'test domain' => ['http://goproperty.test/install', CheckStatus::Warn],
]);

it('needs the document root to be the public folder', function () {
    $report = checkServer(request: Request::create('https://goproperty.ng/install', 'GET', server: ['DOCUMENT_ROOT' => base_path()]));

    expect(findCheck($report, 'The domain serves only the public folder')->blocks())->toBeTrue();
});

it('skips the browser-only checks on the command line', function () {
    $report = (new RequirementsChecker(FakeServer::make()))->check();

    expect(findCheck($report, 'HTTPS')->status)->toBe(CheckStatus::Warn)
        ->and(findCheck($report, 'The domain serves only the public folder')->status)->toBe(CheckStatus::Warn)
        ->and($report->passed())->toBeTrue();
});

it('refuses private files inside the web folder', function () {
    $public = sys_get_temp_dir().'/goproperty-public-'.Str::random(8);
    File::copyDirectory(public_path(), $public);
    file_put_contents($public.'/.env', 'APP_KEY=');
    file_put_contents($public.'/composer.json', '{}');
    $this->app->usePublicPath($public);

    try {
        $check = findCheck(checkServer(request: Request::create('https://goproperty.ng/install', 'GET', server: ['DOCUMENT_ROOT' => $public])), 'No private files in the web folder');
    } finally {
        File::deleteDirectory($public);
    }

    expect($check->blocks())->toBeTrue()
        ->and($check->detail)->toBe('The web folder contains: .env, composer.json.');
});

it('needs the built front-end files', function () {
    $public = sys_get_temp_dir().'/goproperty-public-'.Str::random(8);
    File::ensureDirectoryExists($public);
    $this->app->usePublicPath($public);

    try {
        $check = findCheck(checkServer(request: Request::create('https://goproperty.ng/install', 'GET', server: ['DOCUMENT_ROOT' => $public])), 'Built front-end files (public/build)');
    } finally {
        File::deleteDirectory($public);
    }

    expect($check->blocks())->toBeTrue();
});

it('proves a folder is writable by writing to it', function () {
    $dir = sys_get_temp_dir().'/goproperty-write-'.Str::random(8);
    File::ensureDirectoryExists($dir);

    expect(RequirementsChecker::canWrite($dir))->toBeTrue()
        ->and(scandir($dir))->toBe(['.', '..']);

    chmod($dir, 0555);

    try {
        expect(RequirementsChecker::canWrite($dir))->toBe(function_exists('posix_geteuid') && posix_geteuid() === 0);
    } finally {
        chmod($dir, 0755);
        File::deleteDirectory($dir);
    }

    expect(RequirementsChecker::canWrite($dir))->toBeFalse();
});

it('checks every folder the app writes to', function () {
    $labels = array_map(fn (RequirementCheck $check): string => $check->label, array_values(array_filter(
        checkServer()->checks,
        fn (RequirementCheck $check): bool => $check->group === 'Folders',
    )));

    expect($labels)->toBe([
        'storage/app is writable', 'storage/app/private is writable', 'storage/framework/cache is writable',
        'storage/framework/sessions is writable', 'storage/framework/views is writable', 'storage/logs is writable',
        'bootstrap/cache is writable', 'The application folder (for .env) is writable',
    ]);
});

it('converts PHP size shorthand', function (string $value, int $bytes) {
    expect(RequirementsChecker::bytes($value))->toBe($bytes);
})->with([['256M', 268435456], ['1G', 1073741824], ['512k', 524288], ['1000', 1000], ['-1', -1], ['', -1]]);
