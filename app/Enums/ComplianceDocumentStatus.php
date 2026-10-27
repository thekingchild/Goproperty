<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * A compliance document (DATABASE_SCHEMA.md § 9): `pending` until compliance
 * accepts or rejects it, `expired` once its expiry date has passed
 * (App\Platform\Compliance\DocumentExpiryCheck). Only pending and accepted
 * documents count towards an application's required documents.
 */
enum ComplianceDocumentStatus: string
{
    use HasValues;

    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for review',
            self::Accepted => 'Accepted',
            self::Rejected => 'Rejected',
            self::Expired => 'Expired',
        };
    }

    public function counts(): bool
    {
        return $this === self::Pending || $this === self::Accepted;
    }
}
