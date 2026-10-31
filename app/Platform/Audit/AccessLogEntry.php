<?php

declare(strict_types=1);

namespace App\Platform\Audit;

use App\Audit\AccessLog;
use App\Audit\AuthenticationLog;
use App\Models\PlatformStaff;
use App\Platform\Customers\CustomerUser;
use App\Policies\AccessLogEntryPolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

/**
 * The console's read model of `authentication_logs` (PLATFORM_CONSOLE.md
 * § 8, P06-07c): sign-in events of customers and staff. Read-only: rows
 * are written by App\Audit\AccessLog and pruned after a year, never
 * changed. Authorized by AccessLogEntryPolicy.
 *
 * @property-read CustomerUser|null $user
 * @property-read PlatformStaff|null $staff
 */
#[UsePolicy(AccessLogEntryPolicy::class)]
final class AccessLogEntry extends AuthenticationLog
{
    public const string CUSTOMER = 'user';

    public const string STAFF = 'platform_staff';

    /** Human labels for AuthenticationLog::EVENTS. */
    public const array EVENT_LABELS = [
        'login_success' => 'Signed in',
        'login_failed' => 'Sign-in failed',
        'passkey_login' => 'Signed in with a passkey',
        'logout' => 'Signed out',
        '2fa_passed' => 'Two-factor code accepted',
        '2fa_failed' => 'Two-factor code refused',
        'recovery_code_used' => 'Recovery code used',
        'turnstile_failed' => 'Bot check failed',
        'invitation_accepted' => 'Invitation accepted',
        'reset_link_sent' => 'Password reset link sent',
        'agency_signin_link_created' => 'Sign-in link created by the agency',
        'password_reset' => 'Password reset',
        'lockout' => 'Locked out (too many attempts)',
    ];

    protected $table = 'authentication_logs';

    protected static function booted(): void
    {
        self::saving(fn (): never => throw new LogicException('The access log is written by App\Audit\AccessLog only.'));
        self::deleting(fn (): never => throw new LogicException('The console never deletes access log rows.'));
    }

    /** @return BelongsTo<CustomerUser, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(CustomerUser::class, 'authenticatable_id');
    }

    /** @return BelongsTo<PlatformStaff, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(PlatformStaff::class, 'authenticatable_id');
    }

    /** @return array<string, string> */
    public static function accountTypes(): array
    {
        return [self::CUSTOMER => 'Customer user', self::STAFF => 'GoProperty staff'];
    }

    public static function eventLabel(string $event): string
    {
        return self::EVENT_LABELS[$event] ?? Str::headline($event);
    }

    /**
     * Rows of the accounts with this email (customer users and staff) and
     * failed attempts that typed it, matched by its HMAC (the log never
     * stores the address itself).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public static function forEmail(Builder $query, string $email): Builder
    {
        $email = Str::lower(trim($email));
        $users = CustomerUser::query()->where('email', $email)->pluck('id')->all();
        $staff = PlatformStaff::query()->where('email', $email)->pluck('id')->all();
        $hash = app(AccessLog::class)->hashIdentifier($email);

        return $query->where(function (Builder $match) use ($users, $staff, $hash): void {
            $match->where('attempted_identifier', $hash);

            if ($users !== []) {
                $match->orWhere(fn (Builder $user) => $user->where('authenticatable_type', self::CUSTOMER)->whereIn('authenticatable_id', $users));
            }

            if ($staff !== []) {
                $match->orWhere(fn (Builder $member) => $member->where('authenticatable_type', self::STAFF)->whereIn('authenticatable_id', $staff));
            }
        });
    }

    /** The account by name, or "Unknown account" for attempts on none. */
    public function accountName(): string
    {
        $account = $this->account();

        return $account !== null ? $account->name : 'Unknown account';
    }

    public function accountDetail(): string
    {
        $account = $this->account();
        $type = self::accountTypes()[$this->authenticatable_type] ?? $this->authenticatable_type;

        return $account !== null && $account->email !== null ? $type.' · '.$account->email : $type;
    }

    public function account(): CustomerUser|PlatformStaff|null
    {
        if ($this->authenticatable_id === null) {
            return null;
        }

        return match ($this->authenticatable_type) {
            self::CUSTOMER => $this->user,
            self::STAFF => $this->staff,
            default => null,
        };
    }

    /** The browser, cut to fit a list column. */
    public function shortUserAgent(): ?string
    {
        return $this->user_agent !== null ? Str::limit($this->user_agent, 60) : null;
    }
}
