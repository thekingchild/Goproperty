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
        // dompdf sets a line at line-height × the font's height × this ratio.
        // 1000 / 1210 makes Inter's height (ascender 969, descender -241)
        // exactly 1 em, so lines are as tall as in Chrome
        // (App\Documents\DocumentFonts). dompdf's own default is 1.1.
        'font_height_ratio' => 1000 / 1210,
    ],

    'chrome' => [
        'url' => env('PDF_CHROME_URL'),
        'username' => env('PDF_CHROME_USERNAME'),
        'password' => env('PDF_CHROME_PASSWORD'),
        'timeout' => (int) env('PDF_CHROME_TIMEOUT', 30),
    ],

];
