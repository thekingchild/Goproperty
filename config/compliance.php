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

    /*
    | Transaction Tier monitoring (P06-09, ONBOARDING_COMPLIANCE.md § 5.4–5.5;
    | defaults 88–92 of docs/plans/P06-subtasks.md). Amounts in kobo.
    */
    'monitoring' => [
        // `volume_80`: the rolling 12 months reach this share of the yearly limit.
        'warning_percent' => 80,
        // `volume_100`: days from the flag until the organization is restricted.
        'grace_days' => 30,
        // After a limit is lowered, organizations it puts over get this long (§ 5.2).
        'lowered_limit_days' => 60,
        // Compliance may extend a grace up to this many days from today.
        'max_grace_extension_days' => 90,
        // MLPPA 2022 cash thresholds: a person, a company.
        'cash_threshold_individual_kobo' => 500_000_000,
        'cash_threshold_corporate_kobo' => 1_000_000_000,
        // `possible_structuring`: this many payments within the window, each
        // from this share of a threshold up to just under it.
        'structuring_payments' => 3,
        'structuring_window_days' => 14,
        'structuring_near_percent' => 80,
        // `spike`: the month so far is over this many times the average of
        // the full months before it, and at least the minimum.
        'spike_factor' => 3,
        'spike_months' => 6,
        'spike_minimum_kobo' => 100_000_000,
    ],

];
