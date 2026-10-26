<?php

declare(strict_types=1);

/*
 * P00-04: bootstrap/php-version.php prints one sentence on an old PHP instead
 * of a fatal error (INSTALLER.md § 2). The release drill runs it under PHP 7.4
 * and 8.1 in Docker; these tests cover the message and keep the file free of
 * syntax old PHP versions cannot parse.
 */

$guard = dirname(__DIR__, 2).'/bootstrap/php-version.php';

require_once $guard;

it('explains which PHP the server runs', function (int $versionId, ?string $message) {
    expect(goproperty_php_version_problem($versionId))->toBe($message);
})->with([
    [50640, 'GoProperty needs PHP 8.4.1 or newer; this server runs 5.6.40.'],
    [70433, 'GoProperty needs PHP 8.4.1 or newer; this server runs 7.4.33.'],
    [80130, 'GoProperty needs PHP 8.4.1 or newer; this server runs 8.1.30.'],
    [80399, 'GoProperty needs PHP 8.4.1 or newer; this server runs 8.3.99.'],
    [80400, 'GoProperty needs PHP 8.4.1 or newer; this server runs 8.4.0.'],
    [80401, null],
    [80512, null],
]);

it('lets this PHP through', function () use ($guard) {
    $output = shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($guard).' 2>&1; echo "exit=$?"');

    expect($output)->toBe("exit=0\n");
});

it('uses no syntax that PHP 7 cannot parse', function () use ($guard) {
    $newer = [T_FN, T_MATCH, T_COALESCE_EQUAL, T_NULLSAFE_OBJECT_OPERATOR, T_ATTRIBUTE, T_ENUM, T_READONLY, T_DECLARE, T_NAME_QUALIFIED];
    $found = [];

    foreach (PhpToken::tokenize((string) file_get_contents($guard)) as $token) {
        if ($token->is($newer)) {
            $found[] = $token->getTokenName();
        }
    }

    expect($found)->toBe([]);
});

it('runs before Composer in both entry points', function (string $file, string $require) {
    $contents = (string) file_get_contents(dirname(__DIR__, 2).'/'.$file);

    expect($contents)->toContain($require)
        ->and(strpos($contents, $require))->toBeLessThan(strpos($contents, 'vendor/autoload.php'));
})->with([
    ['artisan', "require __DIR__.'/bootstrap/php-version.php';"],
    ['public/index.php', "require __DIR__.'/../bootstrap/php-version.php';"],
]);
