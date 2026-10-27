<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * How the invitation link reaches the person (`invitations.channel`,
 * ONBOARDING_COMPLIANCE.md § 2). Never SMS (UX_DESIGN.md § 3.1). Member
 * invitations go by email; the agency's WhatsApp (`whatsapp`) and the link
 * or QR code the agency shares itself (`link`) come with tenant
 * invitations (P03-01b).
 */
enum InvitationChannel: string
{
    use HasValues;

    case Email = 'email';
}
