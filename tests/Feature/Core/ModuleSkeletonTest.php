<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Nwidart\Modules\Contracts\ActivatorInterface;
use Nwidart\Modules\Contracts\RepositoryInterface;

/*
 * P00-07: `php artisan module:make` generates modules that follow
 * ARCHITECTURE.md § 8 and suit shared hosting: GoProperty's vendor name, and
 * no per-module package.json, Vite config or asset folders (one Vite build
 * for the whole app; no Node on the server).
 */

beforeEach(function () {
    $this->statuses = (string) file_get_contents(base_path('modules_statuses.json'));
    $this->modules = sys_get_temp_dir().'/goproperty-modules-'.Str::random(8);
    File::ensureDirectoryExists($this->modules);

    config([
        'modules.paths.modules' => $this->modules,
        'modules.activators.file.statuses-file' => $this->modules.'/modules_statuses.json',
    ]);

    // nwidart builds these from the config once; rebuild them for the temporary folder.
    $this->app->forgetInstance(RepositoryInterface::class);
    $this->app->forgetInstance(ActivatorInterface::class);
    $this->app->forgetInstance('modules');
});

afterEach(function () {
    File::deleteDirectory($this->modules);
});

it('generates a module with GoProperty conventions', function () {
    expect(Artisan::call('module:make', ['name' => ['Leasing']]))->toBe(0);

    $module = $this->modules.'/Leasing';
    $composer = json_decode((string) file_get_contents($module.'/composer.json'), true);

    expect($module.'/module.json')->toBeFile()
        ->and($module.'/app/Providers/LeasingServiceProvider.php')->toBeFile()
        ->and($module.'/routes/web.php')->toBeFile()
        ->and($module.'/database/migrations')->toBeDirectory()
        ->and($module.'/tests/Feature')->toBeDirectory()
        ->and($composer['name'])->toBe('goproperty/leasing')
        ->and($composer['authors'][0]['name'])->toBe('GoProperty')
        ->and($composer['autoload']['psr-4'])->toHaveKey('Modules\\Leasing\\')
        ->and($module.'/package.json')->not->toBeFile()
        ->and($module.'/vite.config.js')->not->toBeFile()
        ->and($module.'/resources/assets')->not->toBeDirectory()
        ->and($module.'/routes/api.php')->not->toBeFile()
        // The app's own module statuses are untouched by a module made elsewhere.
        ->and((string) file_get_contents(base_path('modules_statuses.json')))->toBe($this->statuses);
});

/*
 * ARCHITECTURE.md § 8 rules 2–3 (P02-01a switched this on with the first
 * module, Properties): a module's internals are private. Other modules and
 * Core reach a module only through its Contracts and Events.
 */
it('keeps modules apart: nothing outside a module uses its internals', function () {
    $modules = collect(File::directories(base_path('Modules')))->map(fn (string $path): string => basename($path));

    expect($modules)->not->toBeEmpty();

    foreach ($modules as $module) {
        $public = ["Modules\\{$module}\\Contracts", "Modules\\{$module}\\Events"];

        expect('App')->not->toUse("Modules\\{$module}")->ignoring($public);

        foreach ($modules->reject(fn (string $other): bool => $other === $module) as $other) {
            expect("Modules\\{$other}")->not->toUse("Modules\\{$module}")->ignoring($public);
        }
    }
});
