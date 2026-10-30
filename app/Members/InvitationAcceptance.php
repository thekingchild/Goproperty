<?php

declare(strict_types=1);

namespace App\Members;

use App\Audit\AccessLog;
use App\Enums\InvitationKind;
use App\Enums\InvitationStatus;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\Plans\Feature;
use App\Plans\PlanGate;
use App\Plans\PlanUsage;
use App\Rules\NigerianMobile;
use App\Support\PhoneNumber;
use App\Tenancy\OrganizationContext;
use App\Tenancy\OrganizationWriteGuard;
use App\Tenancy\WorkspaceLanding;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use SensitiveParameter;

/**
 * Opening and accepting an invitation link (ONBOARDING_COMPLIANCE.md § 2,
 * UX_DESIGN.md § 3.3). The link is bound to the invited email address:
 *
 * - a person without an account sets their name, an optional mobile number
 *   and a password (the signup rules: 10 characters, breached-password
 *   check in production), and the new account is confirmed (the link
 *   reached that mailbox) and joins;
 * - a person with an account signs in first (the controller sends them to
 *   sign-in and back), then joins;
 * - someone signed in with another account is refused.
 *
 * Unknown, used, revoked and expired links all answer the same way
 * (find() returns null). Accepting is one transaction on the locked row,
 * so a link works once. It records `invitation_accepted` in the
 * authentication log and `invitation.accepted` in the audit log.
 *
 * Finding the invitation: the token is all the page has, so the
 * organization is read from the row by the token's hash first (an
 * unscoped lookup of one column), and the invitation is then loaded inside
 * that organization's context like any organization data. A suspended or
 * cancelled organization takes no new members (OrganizationWriteGuard).
 */
final readonly class InvitationAcceptance
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationWriteGuard $writes,
        private AccessLog $accessLog,
        private MemberAudit $audit,
        private WorkspaceLanding $landing,
        private PlanGate $plans,
        private PlanUsage $usage,
    ) {}

    /**
     * The usable invitation for this link, or null for an unknown, used,
     * revoked or expired one. Records the first opening, and marks a
     * pending invitation past its date as expired.
     */
    public function find(#[SensitiveParameter] string $token): ?Invitation
    {
        $organization = $this->organizationFor($token);

        if ($organization === null) {
            return null;
        }

        return $this->context->run($organization, function () use ($token, $organization): ?Invitation {
            $invitation = Invitation::query()->where('token_hash', MemberInvitations::hash($token))->where('kind', InvitationKind::User)->first();

            if ($invitation === null) {
                return null;
            }

            if ($invitation->status === InvitationStatus::Pending && $invitation->isExpired()) {
                $invitation->forceFill(['status' => InvitationStatus::Expired])->save();
            }

            if (! $invitation->isUsable()) {
                return null;
            }

            if ($invitation->opened_at === null) {
                $invitation->forceFill(['opened_at' => now()])->save();
            }

            return $invitation->setRelation('organization', $organization);
        });
    }

    /** Does an account already use the invited address? Only the link's holder ever learns this. */
    public function hasAccount(Invitation $invitation): bool
    {
        return $invitation->email !== null && User::query()->where('email', $invitation->email)->exists();
    }

    /** Is this signed-in account the one the invitation was sent to? */
    public function isFor(Invitation $invitation, User $user): bool
    {
        return $invitation->email !== null && $user->email !== null
            && Str::lower($user->email) === Str::lower($invitation->email);
    }

    /**
     * A new account for the invited address, joined to the organization.
     *
     * @param  array<string, mixed>  $input  name, phone (optional), password
     *
     * @throws InvitationUnavailable when the link no longer works
     * @throws InvitationNeedsSignIn when the address has an account (sign in instead)
     */
    public function acceptAsNewUser(#[SensitiveParameter] string $token, #[SensitiveParameter] array $input): AcceptedInvitation
    {
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32', new NigerianMobile],
            'password' => ['required', 'string', 'max:255', Password::default()],
        ], attributes: ['phone' => 'mobile number'])->validate();

        return $this->accept($token, function (Invitation $invitation) use ($data): User {
            if ($this->hasAccount($invitation)) {
                throw new InvitationNeedsSignIn;
            }

            $phone = is_string($data['phone'] ?? null) && trim($data['phone']) !== '' ? (string) PhoneNumber::nigerianMobile($data['phone']) : null;

            $user = new User;
            $user->forceFill([
                'name' => trim((string) $data['name']),
                'email' => $invitation->email,
                // A number another account uses is not stored (as at signup: no account is revealed).
                'phone' => $phone !== null && ! User::query()->where('phone', $phone)->exists() ? $phone : null,
                'password' => (string) $data['password'],
                // The link reached this mailbox: the address is confirmed.
                'email_verified_at' => now(),
            ]);

            try {
                $user->save();
            } catch (UniqueConstraintViolationException) {
                throw new InvitationNeedsSignIn;
            }

            return $user;
        });
    }

    /**
     * The signed-in account joins. It must be the invited address
     * (InvitationForAnotherAccount otherwise).
     *
     * @throws InvitationUnavailable
     */
    public function acceptAsExistingUser(#[SensitiveParameter] string $token, User $user): AcceptedInvitation
    {
        return $this->accept($token, function (Invitation $invitation) use ($user): User {
            if (! $this->isFor($invitation, $user)) {
                throw new InvitationForAnotherAccount;
            }

            if ($user->email_verified_at === null) {
                // Opening the emailed link proves the address, as the verification link would.
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            return $user;
        });
    }

    /**
     * @param  callable(Invitation): User  $account
     *
     * @throws InvitationUnavailable
     */
    private function accept(string $token, callable $account): AcceptedInvitation
    {
        $organization = $this->organizationFor($token) ?? throw new InvitationUnavailable;

        $user = $this->context->run($organization, function () use ($token, $account): User {
            $blocked = $this->writes->blockedOrganization();

            if ($blocked !== null) {
                $this->writes->refuse($blocked);
            }

            return DB::transaction(function () use ($token, $account): User {
                $invitation = Invitation::query()->where('token_hash', MemberInvitations::hash($token))->where('kind', InvitationKind::User)->lockForUpdate()->first();

                if ($invitation === null || ! $invitation->isUsable()) {
                    throw new InvitationUnavailable;
                }

                $organization = $this->context->get();

                // Members only: this invitation was counted when it was sent; a smaller plan since may have no room left (P06-01b).
                $this->plans->ensureRoomFor(Feature::StaffUsers, $organization, $this->usage->members());

                $user = $account($invitation);

                $user->joinOrganization($organization, ...$invitation->roleEnums());
                $this->landing->remember($user, $organization);

                $invitation->forceFill([
                    'status' => InvitationStatus::Accepted,
                    'accepted_at' => now(),
                    'accepted_by_user_id' => $user->id,
                ])->save();

                $this->accessLog->record('invitation_accepted', $user);
                $this->audit->record('invitation.accepted', $organization, $user, "{$user->name} accepted the invitation", [
                    'invitation_id' => $invitation->id,
                    'user_id' => $user->id,
                    'email' => $invitation->email,
                    'roles' => $invitation->roles,
                    'invited_by_user_id' => $invitation->invited_by_user_id,
                ]);

                return $user;
            });
        });

        return new AcceptedInvitation($user, $organization);
    }

    /**
     * The organization of the member invitation with this token (one
     * unscoped column read), if any. Other kinds (a billing transfer's link,
     * P02-04) are not opened here: they bring nobody into the organization.
     */
    private function organizationFor(string $token): ?Organization
    {
        if ($token === '' || strlen($token) > 128) {
            return null;
        }

        $id = DB::table('invitations')->where('token_hash', MemberInvitations::hash($token))->where('kind', InvitationKind::User->value)->value('organization_id');

        return is_numeric($id) ? Organization::query()->find((int) $id) : null;
    }
}
