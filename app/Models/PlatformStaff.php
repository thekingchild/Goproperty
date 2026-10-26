<?php

declare(strict_types=1);

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;

/**
 * A GoProperty staff member (DATABASE_SCHEMA.md § 8). Never a customer user:
 * staff sign in on the `staff` guard at the console path (P00-08), with
 * mandatory 2FA and roles on that guard.
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
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'phone', 'password', 'status'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class PlatformStaff extends Authenticatable implements FilamentUser
{
    protected $table = 'platform_staff';

    /** Only active staff, and only the console panel. */
    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'console' && $this->status === StaffStatus::Active;
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
        ];
    }
}
