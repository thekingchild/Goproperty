<?php

declare(strict_types=1);

use App\Installer\Finishing\AdminPath;

/*
 * P00-05: the console path rules (PLATFORM_CONSOLE.md § 10).
 */

it('generates a unique valid path', function () {
    $paths = array_map(fn () => AdminPath::generate(), range(1, 50));

    foreach ($paths as $path) {
        expect($path)->toMatch('/^gp-control-[a-z0-9]{6}$/')
            ->and(AdminPath::isUnique($path))->toBeTrue();
    }

    expect(array_unique($paths))->toHaveCount(50);
});

it('validates paths', function (string $path, bool $valid) {
    expect(AdminPath::isValid($path))->toBe($valid);
})->with([
    ['gp-control', true],
    ['gp-control-k7m2qx', true],
    ['staff-desk-2026', true],
    ['short', false],
    ['admin', false],
    [str_repeat('a', 41), false],
    ['Upper-Case-Path', false],
    ['-leading-hyphen', false],
    ['trailing-hyphen-', false],
    ['has_underscore', false],
    ['has/slash/path', false],
    ["gp-control-k7m2qx\n", false],
]);

it('rejects reserved words', function () {
    foreach (AdminPath::RESERVED as $word) {
        expect(AdminPath::isValid($word))->toBeFalse();
    }
});

it('does not count the shared default as unique', function () {
    expect(AdminPath::isUnique('gp-control'))->toBeFalse();
});
