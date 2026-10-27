<?php

declare(strict_types=1);

namespace App\Documents\Verification;

/**
 * What the verify page says about a document (BRANDING_DOCUMENTS.md § 2
 * item 8, § 3 item 7): genuine and in force, voided (a voided receipt or a
 * void invoice), or superseded (genuine, but replaced by a newer document,
 * e.g. a corrected invoice; VerifiedDocument::$replacedBy names it).
 */
enum VerificationStatus: string
{
    case Valid = 'valid';
    case Voided = 'voided';
    case Superseded = 'superseded';

    public function label(): string
    {
        return match ($this) {
            self::Valid => 'Valid',
            self::Voided => 'Voided',
            self::Superseded => 'Superseded',
        };
    }
}
