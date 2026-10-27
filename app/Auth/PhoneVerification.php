<?php

declare(strict_types=1);

namespace App\Auth;

use App\Enums\PhoneVerificationMethod;
use App\Models\User;

/**
 * Marks a user's phone number as verified, without SMS
 * (DATABASE_SCHEMA.md § 1 `users`, ONBOARDING_COMPLIANCE.md § 2,
 * ARCHITECTURE.md § 6.7): either the person opened a link that reached
 * that number by WhatsApp, or an agency member confirmed it in person
 * (recording who). Only the number the account has now can be verified,
 * and a number already verified keeps its first proof. The caller holds
 * the lock and writes the audit entry; this only sets the columns.
 */
final class PhoneVerification
{
    /** The link opened was sent by WhatsApp to $phone (E.164). */
    public function byWhatsAppLink(User $user, string $phone): bool
    {
        if ($user->phone === null || $user->phone !== $phone || $user->phone_verified_at !== null) {
            return false;
        }

        $user->forceFill([
            'phone_verified_at' => now(),
            'phone_verification_method' => PhoneVerificationMethod::WhatsAppLink,
            'phone_confirmed_by_user_id' => null,
        ])->save();

        return true;
    }

    /** An agency member saw the number in person and vouches for it. */
    public function confirmedInPerson(User $user, string $phone, User $by): bool
    {
        if ($user->phone === null || $user->phone !== $phone || $user->phone_verified_at !== null) {
            return false;
        }

        $user->forceFill([
            'phone_verified_at' => now(),
            'phone_verification_method' => PhoneVerificationMethod::AgencyConfirmed,
            'phone_confirmed_by_user_id' => $by->id,
        ])->save();

        return true;
    }
}
