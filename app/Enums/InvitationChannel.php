<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * How the invitation link reaches the person (`invitations.channel`,
 * ONBOARDING_COMPLIANCE.md § 2). Never SMS (UX_DESIGN.md § 3.1). Member
 * invitations go by email; tenant invitations and sign-in links (P03-01b)
 * also go by the agency's WhatsApp (`whatsapp`: opening the link proves the
 * phone number) or as a link or QR code the agency shares itself (`link`).
 */
enum InvitationChannel: string
{
    use HasValues;

    case Email = 'email';
    case WhatsApp = 'whatsapp';
    case Link = 'link';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'Email',
            self::WhatsApp => 'WhatsApp',
            self::Link => 'Link or QR code',
        };
    }
}
