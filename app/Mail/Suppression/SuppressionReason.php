<?php

declare(strict_types=1);

namespace App\Mail\Suppression;

use App\Enums\Concerns\HasValues;

/** Why no email goes to an address (PLATFORM_CONSOLE.md § 5 "Delivery"). */
enum SuppressionReason: string
{
    use HasValues;

    /** The provider reported a permanent (hard) bounce. */
    case Bounce = 'bounce';

    /** The recipient marked an email as spam. */
    case Complaint = 'complaint';

    /** Added by GoProperty staff. */
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Bounce => 'Bounced',
            self::Complaint => 'Spam complaint',
            self::Manual => 'Added by staff',
        };
    }
}
