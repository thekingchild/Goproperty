<?php

declare(strict_types=1);

namespace App\Members;

use App\Enums\InvitationChannel;
use App\Enums\InvitationKind;
use App\Enums\InvitationStatus;
use App\Enums\OrganizationRole;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\MemberInvitation;
use App\Plans\Feature;
use App\Plans\PlanGate;
use App\Plans\PlanLimitReached;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Member invitations in the current organization (ONBOARDING_COMPLIANCE.md
 * § 2, ARCHITECTURE.md § 6.1): invite an email address with one or more
 * workspace roles, send the link again, revoke it. The only writer of
 * `invitations` besides InvitationAcceptance.
 *
 * - The link is single use and valid for 14 days; only its SHA-256 is
 *   stored. Sending again makes a new link (the old one stops working) and
 *   a new 14 days.
 * - The email is queued on `notifications`, after the commit. No SMS, ever.
 * - Whether the address already has a GoProperty account changes nothing
 *   here or in the email, so inviting reveals no accounts. Members of this
 *   organization and addresses with a pending invitation are refused (the
 *   inviter can see both on the members page anyway).
 * - Every action checks its policy (InvitationPolicy: `members.manage` and
 *   RoleGrants) for the acting user, so the suspended-organization and
 *   impersonation rules apply here too, and is audited (log `members`).
 * - Sends are limited per organization, per inviting user and per address
 *   (ARCHITECTURE.md § 10).
 */
final readonly class MemberInvitations
{
    public const int VALID_DAYS = 14;

    /** Invitation emails (new and sent again) per organization per hour. */
    public const int PER_ORGANIZATION_HOURLY = 50;

    /** Per inviting user per hour. */
    public const int PER_USER_HOURLY = 30;

    /** Per invited address per day. */
    public const int PER_ADDRESS_DAILY = 5;

    public function __construct(
        private OrganizationContext $context,
        private MemberAudit $audit,
        private PlanGate $plans,
    ) {}

    /**
     * Invites an email address into the current organization.
     *
     * @param  list<OrganizationRole>  $roles
     *
     * @throws MemberChangeRefused
     */
    public function invite(User $actor, string $email, array $roles): Invitation
    {
        $email = Str::lower(trim($email));
        $roles = $this->distinct($roles);
        $organization = $this->context->get();

        if ($roles === []) {
            throw new MemberChangeRefused('Choose at least one role.');
        }

        Gate::forUser($actor)->authorize('create', [Invitation::class, $roles]);

        return DB::transaction(function () use ($actor, $email, $roles, $organization): Invitation {
            // One change at a time per organization: no two invitations for one address.
            Organization::query()->whereKey($organization->id)->lockForUpdate()->first();

            if ($this->isMember($email)) {
                throw new MemberChangeRefused('That person is already a member of this organization. Change their roles in the list instead.');
            }

            // Member invitations only: an owner's or tenant's portal invitation to the same address is another matter (P03-15).
            if (Invitation::query()->where('kind', InvitationKind::User)->where('email', $email)->where('status', InvitationStatus::Pending)->where('expires_at', '>', now())->exists()) {
                throw new MemberChangeRefused('That address already has an invitation waiting. Send it again from the list instead.');
            }

            // The plan's staff users, under the organization's lock (P06-01b; PlanGate also refuses the call).
            $this->ensureStaffRoom($organization);

            $this->throttle($actor, $email);

            $token = $this->newToken();
            $invitation = new Invitation;
            $invitation->forceFill([
                'kind' => InvitationKind::User,
                'channel' => InvitationChannel::Email,
                'email' => $email,
                'roles' => array_map(static fn (OrganizationRole $role): string => $role->value, $roles),
                'token_hash' => self::hash($token),
                'status' => InvitationStatus::Pending,
                'expires_at' => now()->addDays(self::VALID_DAYS),
                'sent_at' => now(),
                'send_count' => 1,
                'invited_by_user_id' => $actor->id,
            ])->save();

            $this->audit->record('invitation.sent', $organization, $actor, "Invited {$email}", [
                'invitation_id' => $invitation->id,
                'email' => $email,
                'roles' => $invitation->roles,
                'expires_at' => $invitation->expires_at?->toIso8601String(),
            ]);

            $this->send($invitation, $token, $actor, $organization);

            return $invitation;
        });
    }

    /**
     * Sends the invitation again with a new link and a new 14 days; the
     * old link stops working. An expired invitation can be sent again too.
     *
     * @throws MemberChangeRefused
     */
    public function resend(User $actor, Invitation $invitation): Invitation
    {
        Gate::forUser($actor)->authorize('resend', $invitation);
        $organization = $this->context->get();

        return DB::transaction(function () use ($actor, $invitation, $organization): Invitation {
            $locked = Invitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [InvitationStatus::Pending, InvitationStatus::Expired], true) || $locked->email === null) {
                throw new MemberChangeRefused('This invitation was already used or revoked. Invite the person again instead.');
            }

            // An expired invitation counts again once sent again (P06-01b).
            if ($locked->status === InvitationStatus::Expired || ($locked->expires_at !== null && $locked->expires_at->isPast())) {
                $this->ensureStaffRoom($organization);
            }

            if ($this->isMember($locked->email)) {
                throw new MemberChangeRefused('That person is already a member of this organization.');
            }

            $this->throttle($actor, $locked->email);

            $token = $this->newToken();
            $locked->forceFill([
                'token_hash' => self::hash($token),
                'status' => InvitationStatus::Pending,
                'expires_at' => now()->addDays(self::VALID_DAYS),
                'sent_at' => now(),
                'send_count' => $locked->send_count + 1,
            ])->save();

            $this->audit->record('invitation.resent', $organization, $actor, "Sent the invitation to {$locked->email} again", [
                'invitation_id' => $locked->id,
                'email' => $locked->email,
                'roles' => $locked->roles,
                'expires_at' => $locked->expires_at?->toIso8601String(),
            ]);

            $this->send($locked, $token, $actor, $organization);

            return $locked;
        });
    }

    /**
     * Revokes a pending invitation: its link stops working at once. The row
     * stays (status `cancelled`) for the history.
     *
     * @throws MemberChangeRefused
     */
    public function revoke(User $actor, Invitation $invitation): Invitation
    {
        Gate::forUser($actor)->authorize('revoke', $invitation);
        $organization = $this->context->get();

        return DB::transaction(function () use ($actor, $invitation, $organization): Invitation {
            $locked = Invitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [InvitationStatus::Pending, InvitationStatus::Expired], true)) {
                throw new MemberChangeRefused('This invitation was already used or revoked.');
            }

            $locked->forceFill([
                'status' => InvitationStatus::Cancelled,
                'cancelled_at' => now(),
            ])->save();

            $this->audit->record('invitation.revoked', $organization, $actor, "Revoked the invitation to {$locked->email}", [
                'invitation_id' => $locked->id,
                'email' => $locked->email,
                'roles' => $locked->roles,
            ]);

            return $locked;
        });
    }

    /** @throws MemberChangeRefused */
    private function ensureStaffRoom(Organization $organization): void
    {
        try {
            $this->plans->ensureRoomFor(Feature::StaffUsers, $organization);
        } catch (PlanLimitReached $refused) {
            throw new MemberChangeRefused($refused->getMessage());
        }
    }

    public static function hash(#[SensitiveParameter] string $token): string
    {
        return hash('sha256', $token);
    }

    private function newToken(): string
    {
        return Str::random(48);
    }

    private function isMember(string $email): bool
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null || Membership::query()->where('user_id', $user->id)->doesntExist()) {
            return false;
        }

        // A property owner holding only `property-owner` here (P03-15) is
        // not in the members list (MemberList): they may be invited to work
        // in the workspace too, and joining adds the roles.
        $held = $user->roleNamesIn($this->context->get());

        return $held === [] || array_intersect($held, array_map(static fn (OrganizationRole $role): string => $role->value, RoleGrants::memberRoles())) !== [];
    }

    /**
     * @param  list<OrganizationRole>  $roles
     * @return list<OrganizationRole>
     */
    private function distinct(array $roles): array
    {
        return array_values(array_filter(RoleGrants::memberRoles(), static fn (OrganizationRole $role): bool => in_array($role, $roles, true)));
    }

    /** @throws MemberChangeRefused */
    private function throttle(User $actor, string $email): void
    {
        $limits = [
            'invitations:organization:'.$this->context->id() => [self::PER_ORGANIZATION_HOURLY, 3600],
            'invitations:user:'.$actor->id => [self::PER_USER_HOURLY, 3600],
            'invitations:address:'.hash('sha256', $email) => [self::PER_ADDRESS_DAILY, 86400],
        ];

        foreach ($limits as $key => [$max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw new MemberChangeRefused('Too many invitations have been sent. Try again later.');
            }
        }

        foreach ($limits as $key => [, $decay]) {
            RateLimiter::hit($key, $decay);
        }
    }

    private function send(Invitation $invitation, #[SensitiveParameter] string $token, User $actor, Organization $organization): void
    {
        Notification::route('mail', (string) $invitation->email)->notify(new MemberInvitation(
            token: $token,
            organizationName: $organization->name,
            invitedBy: $actor->name,
            roles: array_map(static fn (OrganizationRole $role): string => $role->label(), $invitation->roleEnums()),
            validDays: self::VALID_DAYS,
        ));
    }
}
