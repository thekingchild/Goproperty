<?php

declare(strict_types=1);

namespace App\Platform\Staff;

use App\Models\ApprovalRequest;
use App\Models\PlatformStaff;
use App\Models\StaffRole;
use App\Models\StaffStatus;
use App\Notifications\StaffInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Staff lifecycle (PLATFORM_CONSOLE.md § 1): invite by email → set password
 * and enrol 2FA on first sign-in → active; deactivate (which ends every
 * session at the next request, see StaffAccountState) and reactivate.
 * Every step is audited with the acting staff member.
 */
final class StaffLifecycle
{
    public const int INVITATION_DAYS = 7;

    public function __construct(
        private readonly StaffAudit $audit,
        private readonly StaffRoles $roles,
        private readonly SuperAdminFloor $floor,
    ) {}

    /**
     * Creates the invited account and emails the link. Roles that need a
     * second staff member come back as approval requests.
     *
     * @param  list<StaffRole>  $roles
     * @return array{0: PlatformStaff, 1: list<ApprovalRequest>}
     *
     * @throws ValidationException
     */
    public function invite(PlatformStaff $actor, string $name, string $email, ?string $phone = null, array $roles = [], ?string $reason = null): array
    {
        if (! $actor->holdsStaffPermission(StaffPermission::StaffInvite)) {
            throw new StaffChangeRefused('You do not have permission to invite staff.');
        }

        if ($roles !== [] && ! $actor->holdsStaffPermission(StaffPermission::RolesManage)) {
            throw new StaffChangeRefused('Inviting with roles needs permission to manage roles. Invite without roles, and ask a super-admin to grant them.');
        }

        $email = strtolower(trim($email));
        Validator::make(['name' => $name, 'email' => $email, 'phone' => $phone], [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:254', 'unique:platform_staff,email'],
            'phone' => ['nullable', 'string', 'max:32'],
        ], ['email.unique' => 'A staff account with this email already exists.'])->validate();

        $token = Str::random(64);

        [$staff, $requests] = DB::transaction(function () use ($actor, $name, $email, $phone, $roles, $reason, $token): array {
            $staff = new PlatformStaff;
            $staff->forceFill([
                'name' => trim($name),
                'email' => $email,
                'phone' => $phone !== null && trim($phone) !== '' ? trim($phone) : null,
                // Nobody knows this password: the invitee sets their own.
                'password' => Str::random(64),
                'status' => StaffStatus::Invited,
                'invited_by_staff_id' => $actor->id,
                'invitation_token_hash' => self::hash($token),
                'invitation_expires_at' => now()->addDays(self::INVITATION_DAYS),
            ])->save();

            $this->audit->record('staff.invited', $staff, $actor, "staff {$email} invited", array_filter([
                'roles' => array_map(fn (StaffRole $role): string => $role->name, $roles),
                'reason' => $reason,
            ]));

            $requests = [];

            foreach ($roles as $role) {
                $request = $this->roles->grant($actor, $staff, $role, $reason);

                if ($request !== null) {
                    $requests[] = $request;
                }
            }

            return [$staff, $requests];
        });

        $staff->notify(new StaffInvitation($token, $actor->name, self::INVITATION_DAYS));

        return [$staff, $requests];
    }

    public function resendInvitation(PlatformStaff $actor, PlatformStaff $staff): void
    {
        if (! $actor->holdsStaffPermission(StaffPermission::StaffInvite)) {
            throw new StaffChangeRefused('You do not have permission to invite staff.');
        }

        if ($staff->status !== StaffStatus::Invited || $staff->invitation_accepted_at !== null) {
            throw new StaffChangeRefused('Only a pending invitation can be sent again.');
        }

        $token = Str::random(64);
        $staff->forceFill([
            'invitation_token_hash' => self::hash($token),
            'invitation_expires_at' => now()->addDays(self::INVITATION_DAYS),
        ])->save();

        $this->audit->record('staff.invitation_resent', $staff, $actor, "invitation sent again to {$staff->email}");
        $staff->notify(new StaffInvitation($token, $actor->name, self::INVITATION_DAYS));
    }

    /** The invited staff member a link belongs to, while it is still usable. */
    public function findInvitation(#[SensitiveParameter] string $token): ?PlatformStaff
    {
        if ($token === '') {
            return null;
        }

        return PlatformStaff::query()
            ->where('invitation_token_hash', self::hash($token))
            ->where('status', StaffStatus::Invited)
            ->whereNull('invitation_accepted_at')
            ->where('invitation_expires_at', '>', now())
            ->first();
    }

    /**
     * First sign-in, part one: the invitee sets a password. The account
     * stays `invited` until 2FA is enrolled (activateIfReady).
     *
     * @throws ValidationException
     */
    public function acceptInvitation(#[SensitiveParameter] string $token, #[SensitiveParameter] string $password): PlatformStaff
    {
        Validator::make(['password' => $password], ['password' => ['required', 'string', self::passwordRule()]])->validate();

        return DB::transaction(function () use ($token, $password): PlatformStaff {
            $staff = $this->findInvitation($token);

            if ($staff === null || PlatformStaff::query()->whereKey($staff->getKey())->lockForUpdate()->value('invitation_accepted_at') !== null) {
                throw new StaffChangeRefused('This invitation link is not valid any more. Ask for a new one.');
            }

            $staff->forceFill([
                'password' => $password,
                'invitation_accepted_at' => now(),
                'invitation_token_hash' => null,
                'invitation_expires_at' => null,
                'remember_token' => Str::random(60),
            ])->save();

            $this->audit->record('staff.invitation_accepted', $staff, $staff, "invitation accepted by {$staff->email}");

            return $staff;
        });
    }

    /**
     * First sign-in, part two: once an invitee who set a password has 2FA,
     * the account becomes active. True when it did.
     */
    public function activateIfReady(PlatformStaff $staff): bool
    {
        if (! $staff->hasAcceptedInvitation() || ! $staff->hasTwoFactorEnabled()) {
            return false;
        }

        $staff->forceFill(['status' => StaffStatus::Active])->save();
        $this->audit->record('staff.activated', $staff, $staff, "staff {$staff->email} activated (password and 2FA set)");

        return true;
    }

    /**
     * Deactivation ends every session of the account at its next request
     * (StaffAccountState) and keeps the row (no hard deletes).
     */
    public function deactivate(PlatformStaff $actor, PlatformStaff $staff, ?string $reason = null): void
    {
        $this->assertMayManage($actor);

        if ($actor->is($staff)) {
            throw new StaffChangeRefused('You cannot deactivate your own account.');
        }

        if ($staff->status === StaffStatus::Deactivated) {
            throw new StaffChangeRefused("{$staff->name} is already deactivated.");
        }

        $this->floor->guard($staff, function () use ($actor, $staff, $reason): void {
            $from = $staff->status;

            $staff->forceFill([
                'status' => StaffStatus::Deactivated,
                'deactivated_at' => now(),
                'remember_token' => Str::random(60),
                'invitation_token_hash' => null,
                'invitation_expires_at' => null,
            ])->save();

            $this->audit->record('staff.deactivated', $staff, $actor, "staff {$staff->email} deactivated", array_filter([
                'from' => $from->value,
                'reason' => $reason,
            ]));
        });
    }

    public function reactivate(PlatformStaff $actor, PlatformStaff $staff, ?string $reason = null): void
    {
        $this->assertMayManage($actor);

        if ($staff->status !== StaffStatus::Deactivated) {
            throw new StaffChangeRefused("{$staff->name} is not deactivated.");
        }

        // Someone deactivated before finishing their first sign-in is back
        // to `invited` (send the invitation again if they never set a password).
        $status = $staff->invited_by_staff_id !== null && ($staff->invitation_accepted_at === null || ! $staff->hasTwoFactorEnabled())
            ? StaffStatus::Invited
            : StaffStatus::Active;

        $staff->forceFill(['status' => $status, 'deactivated_at' => null])->save();

        $this->audit->record('staff.reactivated', $staff, $actor, "staff {$staff->email} reactivated", array_filter([
            'status' => $status->value,
            'reason' => $reason,
        ]));
    }

    /**
     * Name, phone and the optional IP allowlist (addresses or CIDR ranges).
     *
     * @param  list<string>  $ipAllowlist
     *
     * @throws ValidationException
     */
    public function updateDetails(PlatformStaff $actor, PlatformStaff $staff, string $name, ?string $phone, array $ipAllowlist): void
    {
        $this->assertMayManage($actor);

        $ipAllowlist = array_values(array_unique(array_filter(array_map('trim', $ipAllowlist), fn (string $entry): bool => $entry !== '')));

        Validator::make(['name' => $name, 'phone' => $phone, 'ip_allowlist' => $ipAllowlist], [
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:32'],
            'ip_allowlist' => ['array', 'max:50'],
            'ip_allowlist.*' => ['string', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value) || ! self::isIpOrRange($value)) {
                    $fail('Each allowlist entry must be an IP address or a CIDR range.');
                }
            }],
        ])->validate();

        $before = ['name' => $staff->name, 'phone' => $staff->phone, 'ip_allowlist' => $staff->ip_allowlist ?? []];
        $after = ['name' => trim($name), 'phone' => $phone !== null && trim($phone) !== '' ? trim($phone) : null, 'ip_allowlist' => $ipAllowlist];
        $changes = [];

        foreach ($after as $key => $value) {
            if ($before[$key] !== $value) {
                $changes[$key] = ['old' => $before[$key], 'new' => $value];
            }
        }

        if ($changes === []) {
            return;
        }

        $staff->forceFill(['name' => $after['name'], 'phone' => $after['phone'], 'ip_allowlist' => $ipAllowlist === [] ? null : $ipAllowlist])->save();
        $this->audit->record('staff.updated', $staff, $actor, "staff {$staff->email} details changed", $changes);
    }

    /** Staff passwords: at least 12 characters, as for the first super-admin. */
    public static function passwordRule(): Password
    {
        return app()->isProduction() ? Password::min(12)->uncompromised() : Password::min(12);
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private static function isIpOrRange(string $value): bool
    {
        [$address, $bits] = array_pad(explode('/', $value, 2), 2, null);

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if ($bits === null) {
            return true;
        }

        $max = str_contains((string) $address, ':') ? 128 : 32;

        return ctype_digit($bits) && (int) $bits <= $max;
    }

    private function assertMayManage(PlatformStaff $actor): void
    {
        if (! $actor->holdsStaffPermission(StaffPermission::StaffManage)) {
            throw new StaffChangeRefused('You do not have permission to manage staff.');
        }
    }
}
