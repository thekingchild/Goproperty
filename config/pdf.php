<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | PDF driver
    |--------------------------------------------------------------------------
    |
    | "dompdf": pure PHP, works on shared hosting. "chrome": headless Chrome
    | through a Gotenberg service over HTTP (VPS add-on), so the PHP server
    | needs neither Node nor `proc_open`. Templates use table layouts and
    | simple CSS so both render the same (BRANDING_DOCUMENTS.md § 5).
    |
    */

    'driver' => env('PDF_DRIVER', env('HOSTING_PROFILE', 'shared') === 'vps' ? 'chrome' : 'dompdf'),

    'dompdf' => [
        // Scratch files and fonts registered at runtime (Inter, BRANDING_DOCUMENTS.md § 5).
        'cache_dir' => storage_path('framework/cache/dompdf'),
        'default_font' => 'DejaVu Sans',
        'dpi' => 96,
    ],

    'chrome' => [
        'url' => env('PDF_CHROME_URL'),
        'username' => env('PDF_CHROME_USERNAME'),
        'password' => env('PDF_CHROME_PASSWORD'),
        'timeout' => (int) env('PDF_CHROME_TIMEOUT', 30),
    ],

];
