<?php

declare(strict_types=1);

namespace App\Audit;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One sign-in event for a customer or a staff member (PLATFORM_CONSOLE.md
 * § 8). Written once, never edited; rows older than a year are pruned.
 *
 * @property int $id
 * @property string $authenticatable_type user|platform_staff
 * @property int|null $authenticatable_id
 * @property string|null $attempted_identifier HMAC of the typed email or phone
 * @property string $event
 * @property string|null $ip
 * @property string|null $user_agent
 * @property string|null $location
 * @property bool|null $is_new_device
 * @property Carbon|null $created_at
 */
#[Fillable(['authenticatable_type', 'authenticatable_id', 'attempted_identifier', 'event', 'ip', 'user_agent', 'location', 'is_new_device', 'created_at'])]
class AuthenticationLog extends Model
{
    public const array EVENTS = [
        'login_success', 'login_failed', 'passkey_login', 'logout', '2fa_passed', '2fa_failed', 'recovery_code_used',
        'turnstile_failed', 'invitation_accepted', 'reset_link_sent', 'agency_signin_link_created', 'password_reset', 'lockout',
    ];

    public const UPDATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_new_device' => 'boolean',
            'created_at' => 'datetime',
        ];
    }
}
