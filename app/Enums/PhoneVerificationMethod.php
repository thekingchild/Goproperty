<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * How a user's phone number was verified (`users.phone_verification_method`,
 * DATABASE_SCHEMA.md § 1). Never by an SMS code: the person opened an
 * invitation or sign-in link the agency sent to that number by WhatsApp,
 * or the agency confirmed the number in person (ONBOARDING_COMPLIANCE.md
 * § 2, ARCHITECTURE.md § 6.7). Only verified or confirmed numbers will get
 * SMS reminders (P05).
 */
enum PhoneVerificationMethod: string
{
    use HasValues;

    case WhatsAppLink = 'whatsapp_link';
    case AgencyConfirmed = 'agency_confirmed';

    public function label(): string
    {
        return match ($this) {
            self::WhatsAppLink => 'Verified by a WhatsApp link',
            self::AgencyConfirmed => 'Confirmed in person by the agency',
        };
    }
}
