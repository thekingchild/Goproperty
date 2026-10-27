<?php

declare(strict_types=1);

namespace Modules\Tenants\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Where a tenant's identity check stands, `tenant_profiles.kyc_status`
 * (DATABASE_SCHEMA.md § 1). New and imported tenants start unverified; an
 * import never marks a tenant verified (the agency checks the ID itself).
 */
enum KycStatus: string
{
    use HasValues;

    case Unverified = 'unverified';
    case Pending = 'pending';
    case Verified = 'verified';

    public function label(): string
    {
        return match ($this) {
            self::Unverified => 'Not verified',
            self::Pending => 'Being checked',
            self::Verified => 'Verified',
        };
    }
}
