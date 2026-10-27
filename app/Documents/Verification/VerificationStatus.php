<?php

declare(strict_types=1);

namespace App\Documents\Verification;

/** What the verify page says about a document (BRANDING_DOCUMENTS.md § 3 item 7). */
enum VerificationStatus: string
{
    case Valid = 'valid';
    case Voided = 'voided';
}
