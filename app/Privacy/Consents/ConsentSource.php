<?php

declare(strict_types=1);

namespace App\Privacy\Consents;

use App\Enums\Concerns\HasValues;

/** Where a consent was given (`consent_records.source`). */
enum ConsentSource: string
{
    use HasValues;

    case Signup = 'signup';
    case MemberInvitation = 'member_invitation';
    case TenantInvitation = 'tenant_invitation';
    case OwnerInvitation = 'owner_invitation';
    case NotificationSettings = 'notification_settings';

    public function label(): string
    {
        return match ($this) {
            self::Signup => 'Signup',
            self::MemberInvitation => 'Team invitation accepted',
            self::TenantInvitation => 'Tenant portal invitation accepted',
            self::OwnerInvitation => 'Owner portal invitation accepted',
            self::NotificationSettings => 'Notification settings',
        };
    }
}
