<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Compliance documents (ONBOARDING_COMPLIANCE.md § 5.3, ARCHITECTURE.md § 9)
|--------------------------------------------------------------------------
|
| Transaction Tier documents live on their own private disk, never the
| public one and never the media library's: `compliance` (local, under
| storage/app/compliance) by default, `compliance-s3` (its own S3-compatible
| bucket, COMPLIANCE_S3_*) in production. Each file is encrypted with the
| application key before it is written, so the bucket only ever holds
| ciphertext. Staff open files only through the console's signed,
| short-lived, audited route (App\Http\Controllers\Console\ComplianceDocumentController).
|
*/

return [

    'disk' => env('COMPLIANCE_DISK', 'compliance'),

    // Per file, before encryption (which adds about a third).
    'max_upload_kb' => (int) env('COMPLIANCE_MAX_UPLOAD_KB', 8192),

    // Sniffed from the file's content, never trusted from the browser.
    'allowed_mimes' => [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ],

    // How long a staff member's link to a document works.
    'signed_url_minutes' => 5,

    // Owners are told this many days before a document expires (§ 5.3).
    'expiry_warning_days' => 30,

];
