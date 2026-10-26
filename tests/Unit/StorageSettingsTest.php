<?php

declare(strict_types=1);

use App\Installer\StorageSettings;

/*
 * P01-09: the file storage settings the wizard tests and writes to .env
 * (INSTALLER.md § 5, step 5).
 */

it('accepts S3-compatible settings and writes the s3 disk', function () {
    $settings = new StorageSettings('https://abc.r2.cloudflarestorage.com/', 'auto', 'gp-files', 'AKIAEXAMPLE1234', 'se/cr+et=', true);

    expect($settings->endpoint)->toBe('https://abc.r2.cloudflarestorage.com')
        ->and($settings->environment())->toBe([
            'AWS_ACCESS_KEY_ID' => 'AKIAEXAMPLE1234',
            'AWS_SECRET_ACCESS_KEY' => 'se/cr+et=',
            'AWS_DEFAULT_REGION' => 'auto',
            'AWS_BUCKET' => 'gp-files',
            'AWS_ENDPOINT' => 'https://abc.r2.cloudflarestorage.com',
            'AWS_USE_PATH_STYLE_ENDPOINT' => true,
        ])
        ->and($settings->diskConfig())->toMatchArray(['driver' => 's3', 'throw' => true, 'use_path_style_endpoint' => true])
        ->and(print_r($settings, true))->not->toContain('se/cr+et=')
        ->and($settings->scrub('key AKIAEXAMPLE1234 secret se/cr+et='))->toBe('key *** secret ***');
});

it('leaves the endpoint empty for AWS S3', function () {
    expect((new StorageSettings('', 'eu-west-1', 'gp-files', 'AKIAEXAMPLE1234', 'secret', false))->diskConfig()['endpoint'])->toBeNull();
});

it('refuses settings that cannot be right', function (array $values, string $message) {
    expect(fn () => new StorageSettings(...$values))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'plain http endpoint' => [['http://storage.example.com', 'auto', 'gp-files', 'AKIAEXAMPLE1234', 'secret', false], 'https://'],
    'endpoint with credentials' => [['https://user:pass@storage.example.com', 'auto', 'gp-files', 'AKIAEXAMPLE1234', 'secret', false], 'https://'],
    'region' => [['', 'EU West', 'gp-files', 'AKIAEXAMPLE1234', 'secret', false], 'region'],
    'bucket' => [['', 'auto', 'GP_Files', 'AKIAEXAMPLE1234', 'secret', false], 'bucket'],
    'key' => [['', 'auto', 'gp-files', 'AKIA EXAMPLE', 'secret', false], 'access key'],
    'empty secret' => [['', 'auto', 'gp-files', 'AKIAEXAMPLE1234', '', false], 'secret'],
    'secret with a new line' => [['', 'auto', 'gp-files', 'AKIAEXAMPLE1234', "sec\nret", false], 'secret'],
]);

it('allows http only for a local test endpoint', function () {
    expect((new StorageSettings('http://127.0.0.1:9000', 'us-east-1', 'gp-files', 'minioadmin', 'minioadmin', true))->endpoint)->toBe('http://127.0.0.1:9000');
});
