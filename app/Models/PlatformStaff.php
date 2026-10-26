<?php

declare(strict_types=1);

namespace App\Models;

use App\Audit\AuditEntry;
use App\Platform\Console\HasStaffRoles;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use SensitiveParameter;

/**
 * A GoProperty staff member (DATABASE_SCHEMA.md § 8). Never a customer user:
 * staff sign in on the `staff` guard at the console path (P00-08), with
 * mandatory 2FA (P00-10c) and global spatie roles on that guard
 * (PLATFORM_CONSOLE.md § 1, P01-07). Lifecycle: invited → (password set,
 * then 2FA enrolled) active → deactivated, through
 * App\Platform\Staff\StaffLifecycle.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property StaffStatus $status
 * @property list<string>|null $ip_allowlist
 * @property Carbon|null $last_login_at
 * @property int|null $invited_by_staff_id
 * @property string|null $invitation_token_hash
 * @property Carbon|null $invitation_expires_at
 * @property Carbon|null $invitation_accepted_at
 * @property Carbon|null $deactivated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'phone', 'password', 'status'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', 'invitation_token_hash'])]
class PlatformStaff extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    use HasStaffRoles;
    use Notifiable;

    protected $table = 'platform_staff';

    /**
     * Only the console panel; active staff, plus invited staff who have set
     * their password, so they can reach the 2FA set-up page that every
     * other console page redirects them to (first sign-in, § 1).
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'console' && ($this->status === StaffStatus::Active || $this->hasAcceptedInvitation());
    }

    public function hasAcceptedInvitation(): bool
    {
        return $this->status === StaffStatus::Invited && $this->invitation_accepted_at !== null;
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null && filled($this->two_factor_secret);
    }

    /** Console permissions need an active account with 2FA enrolled. */
    protected function mayUseStaffPermissions(): bool
    {
        return $this->status === StaffStatus::Active && $this->hasTwoFactorEnabled();
    }

    /** @return BelongsTo<PlatformStaff, $this> */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'invited_by_staff_id');
    }

    /**
     * Audit entries about this staff member: invitation, role grants and
     * removals, activation, deactivation (the profile history, § 1).
     *
     * @return MorphMany<AuditEntry, $this>
     */
    public function history(): MorphMany
    {
        return $this->morphMany(AuditEntry::class, 'subject');
    }

    /*
     * Mandatory 2FA (UX_DESIGN.md § 3.2) through Filament's authenticator-app
     * provider, stored in the two_factor_* columns. Recovery codes are kept
     * hashed (Filament hashes them before saving).
     */

    public function getAppAuthenticationSecret(): ?string
    {
        return $this->two_factor_secret;
    }

    public function saveAppAuthenticationSecret(#[SensitiveParameter] ?string $secret): void
    {
        $this->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => $secret === null ? null : now(),
            'two_factor_recovery_codes' => $secret === null ? null : $this->two_factor_recovery_codes,
        ])->save();
    }

    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    /** @return array<string>|null */
    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        $codes = json_decode((string) $this->two_factor_recovery_codes, true);

        return is_array($codes) ? array_values(array_filter($codes, 'is_string')) : null;
    }

    /** @param  array<string>|null  $codes */
    public function saveAppAuthenticationRecoveryCodes(#[SensitiveParameter] ?array $codes): void
    {
        $this->forceFill(['two_factor_recovery_codes' => $codes === null ? null : json_encode(array_values($codes))])->save();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
            'status' => StaffStatus::class,
            'ip_allowlist' => 'array',
            'last_login_at' => 'datetime',
            'invitation_expires_at' => 'datetime',
            'invitation_accepted_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }
}
