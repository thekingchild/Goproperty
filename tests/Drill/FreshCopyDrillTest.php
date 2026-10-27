<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/*
 * P00-05 acceptance, as a real server sees it: a fresh copy with no APP_KEY
 * redirects to /install without touching the database or writing error logs
 * (INSTALLER.md § 3). The database points at a port where nothing listens
 * and the site gets an empty storage folder of its own, so any database use
 * or log line would show.
 */

it('sends a fresh copy to the installer without the database or the error log', function () {
    $storage = sys_get_temp_dir().'/goproperty-fresh-'.Str::random(10);

    foreach (['app/private', 'app/public', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $folder) {
        File::ensureDirectoryExists("{$storage}/{$folder}");
    }

    $port = random_int(20000, 40000);
    $base = "http://127.0.0.1:{$port}";

    $server = Process::path(base_path())
        ->env([
            'APP_KEY' => '',
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'HOSTING_PROFILE' => 'shared',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '1',
            'DB_DATABASE' => 'none',
            'DB_USERNAME' => 'nobody',
            'DB_PASSWORD' => 'nothing',
            'LOG_CHANNEL' => 'single',
            'LARAVEL_STORAGE_PATH' => $storage,
        ])
        // EGPCS: Laravel reads LARAVEL_STORAGE_PATH from $_ENV.
        ->start([PHP_BINARY, '-d', 'variables_order=EGPCS', '-S', "127.0.0.1:{$port}", '-t', public_path()]);

    try {
        $ready = false;

        // Up to ~20 s: under a loaded parallel run `php -S` can take a while to listen.
        for ($i = 0; $i < 200 && ! $ready; $i++) {
            try {
                Http::timeout(1)->get("{$base}/install.css");
                $ready = true;
            } catch (Throwable) {
                usleep(100_000);
            }
        }

        expect($ready)->toBeTrue('The PHP server did not start.');

        $client = Http::withoutRedirecting()->timeout(10);

        foreach (['/', '/login', '/register', '/dashboard', '/up'] as $path) {
            $response = $client->get($base.$path);

            expect($response->status())->toBe(302, "GET {$path}")
                ->and($response->header('Location'))->toBe('/install')
                ->and($response->header('Set-Cookie'))->toBe('');
        }

        $write = $client->asForm()->post("{$base}/login", ['email' => 'a@example.com', 'password' => 'x']);

        expect($write->status())->toBe(503)
            ->and($write->body())->toBe("This site is being set up. Please try again later.\n")
            ->and($write->header('Retry-After'))->toBe('300');

        $install = $client->get("{$base}/install");

        expect($install->status())->toBe(200)
            ->and($install->body())->toContain('Install GoProperty')
            ->and($install->header('Set-Cookie'))->toBe('')
            ->and($client->get("{$base}/install.css")->status())->toBe(200);
    } finally {
        $server->stop();
        $output = $server->output().$server->errorOutput();
        $logs = File::allFiles("{$storage}/logs");
        File::deleteDirectory($storage);
    }

    expect($logs)->toBe([], 'The fresh copy wrote a log file.')
        ->and($output)->not->toMatch('/PHP (Fatal|Warning|Notice|Deprecated)/');
});
