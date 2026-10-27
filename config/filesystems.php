<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        // Platform brand images (PLATFORM_CONSOLE.md § 9.2): private, served
        // through /brand/{role} by App\Http\Controllers\BrandAssetController.
        'brand' => [
            'driver' => 'local',
            'root' => storage_path('app/brand'),
            'throw' => true,
            'report' => false,
        ],

        // Transaction Tier documents (config/compliance.php): private,
        // encrypted by the app, opened by staff only through the console's
        // signed and audited route. Never served directly.
        'compliance' => [
            'driver' => 'local',
            'root' => storage_path('app/compliance'),
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ],

        // The production choice (COMPLIANCE_DISK=compliance-s3): a separate,
        // stricter private bucket from any other media (ARCHITECTURE.md § 9).
        'compliance-s3' => [
            'driver' => 's3',
            'key' => env('COMPLIANCE_S3_ACCESS_KEY_ID'),
            'secret' => env('COMPLIANCE_S3_SECRET_ACCESS_KEY'),
            'region' => env('COMPLIANCE_S3_REGION', 'us-east-1'),
            'bucket' => env('COMPLIANCE_S3_BUCKET'),
            'endpoint' => env('COMPLIANCE_S3_ENDPOINT'),
            'use_path_style_endpoint' => (bool) env('COMPLIANCE_S3_USE_PATH_STYLE_ENDPOINT', false),
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim((string) env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
