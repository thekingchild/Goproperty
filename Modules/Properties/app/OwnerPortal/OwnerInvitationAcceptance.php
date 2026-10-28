<?php

declare(strict_types=1);

namespace Modules\Properties\OwnerPortal;

use App\Audit\AccessLog;
use App\Auth\PhoneVerification;
use App\Enums\InvitationChannel;
use App\Enums\InvitationKind;
use App\Enums\InvitationStatus;
use App\Members\InvitationForAnotherAccount;
use App\Members\InvitationNeedsSignIn;
use App\Members\InvitationUnavailable;
use App\Members\MemberInvitations;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use App\Tenancy\OrganizationWriteGuard;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Modules\Properties\Models\OwnerProfile;
use SensitiveParameter;

/**
 * Opening and accepting an owner's portal invitation (kind `owner`,
 * P03-15), with the tenant invitations' rules (P03-01b,
 * ONBOARDING_COMPLIANCE.md § 2). Accepting links the owner profile to a
 * user (`owner_profiles.user_id`, the only column of the profile written
 * here) and makes the user a member holding `property-owner` only
 * (ARCHITECTURE.md § 5; BILLING_PAYMENTS.md § 7.4), which never opens the
 * workspace: the owner follows the buildings in /owner.
 *
 * **No account takeover.** The link is bound to what it was sent to: the
 * email address (channel `email`), or the owner's phone number
 * (`whatsapp` and `link`). Holding the link proves at most that one inbox
 * or phone (and for `link` nothing at all: the agency handed it over),
 * never control of an account, so:
 * - an account that already has that address or number must sign in first
 *   (password, passkey and 2FA as usual) and then accepts: the link never
 *   signs anyone in to an existing account;
 * - someone signed in with another account may accept only if that
 *   account has no address (`email`) or no number (`whatsapp`, `link`) yet
 *   and nobody else holds it: the account then gains it (the address
 *   confirmed; a WhatsApp-delivered number verified); otherwise the link is
 *   for another account (403);
 * - a new account gets only what the link proves: the address (confirmed)
 *   for `email`, plus the owner's number when no account holds it; the
 *   number for `whatsapp` (verified, `whatsapp_link`) and `link` (not
 *   verified);
 * - an account already linked to another owner profile of the same
 *   organization is refused (one profile per user there).
 *
 * Unknown, used, revoked and expired links all answer the same (find()
 * null). The organization comes from the token's hash first (one unscoped
 * column, as P01-04 does), then everything is read inside its context, so
 * an invitation can only ever reach its own organization's owner. A
 * suspended or cancelled organization links nobody (OrganizationWriteGuard).
 * Accepting is one transaction on the locked invitation and owner, so a
 * link works once; it writes `invitation_accepted` to the authentication
 * log and `owner.portal_access_granted` to the audit log.
 */
final readonly class OwnerInvitationAcceptance
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationWriteGuard $writes,
        private AccessLog $accessLog,
        private OwnerAccessAudit $audit,
        private PhoneVerification $phones,
        private OwnerLinks $links,
    ) {}

    /** The usable invitation for this link, or null. Records the first opening; marks a pending one past its date expired. */
    public function find(#[SensitiveParameter] string $token): ?OpenedOwnerInvitation
    {
        $organization = $this->organizationFor($token);

        if ($organization === null) {
            return null;
        }

        return $this->context->run($organization, function () use ($token, $organization): ?OpenedOwnerInvitation {
            $invitation = $this->invitation($token);

            if ($invitation === null) {
                return null;
            }

            if ($invitation->status === InvitationStatus::Pending && $invitation->isExpired()) {
                $invitation->forceFill(['status' => InvitationStatus::Expired])->save();
            }

            $owner = $invitation->owner_profile_id !== null ? OwnerProfile::query()->find($invitation->owner_profile_id) : null;

            if (! $invitation->isUsable() || $owner === null || $owner->user_id !== null) {
                return null;
            }

            if ($invitation->opened_at === null) {
                $invitation->forceFill(['opened_at' => now()])->save();
            }

            return new OpenedOwnerInvitation($invitation, $owner, $organization);
        });
    }

    /**
     * What the page offers: `create` (set a password), `sign-in` (an account
     * holds the address or number: sign in first), `join` (signed in as the
     * account the link is for), `wrong-account`, or `linked-elsewhere` (the
     * account already follows another owner's buildings in this
     * organization). Inside the invitation's organization.
     */
    public function mode(Invitation $invitation, ?User $signedIn): string
    {
        $holder = $this->holder($invitation);

        if ($signedIn === null) {
            return $holder !== null ? 'sign-in' : 'create';
        }

        $joins = $holder !== null ? $holder->is($signedIn) : $this->lacksIdentifier($invitation, $signedIn);

        if (! $joins) {
            return 'wrong-account';
        }

        return $this->linkedToAnotherOwner($invitation, $signedIn) ? 'linked-elsewhere' : 'join';
    }

    /**
     * A new account for the person the link reached, linked to the owner.
     *
     * @param  array<string, mixed>  $input  name, password
     *
     * @throws InvitationUnavailable
     * @throws InvitationNeedsSignIn when an account holds the address or number
     */
    public function acceptAsNewUser(#[SensitiveParameter] string $token, #[SensitiveParameter] array $input): AcceptedOwnerInvitation
    {
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255', Password::default()],
        ])->validate();

        return $this->accept($token, null, function (Invitation $invitation, OwnerProfile $owner) use ($data): User {
            if ($this->holder($invitation) !== null) {
                throw new InvitationNeedsSignIn;
            }

            $byEmail = $invitation->channel === InvitationChannel::Email;
            // With an emailed link, the owner's number is the agency's record, unproven: kept only when no account holds it.
            $phone = $byEmail
                ? ($owner->phone !== null && $owner->phone !== '' && ! User::query()->where('phone', $owner->phone)->exists() ? $owner->phone : null)
                : $invitation->phone;

            $user = new User;
            $user->forceFill([
                'name' => trim((string) $data['name']),
                'email' => $byEmail ? $invitation->email : null,
                'email_verified_at' => $byEmail ? now() : null,
                'phone' => $phone,
                'password' => (string) $data['password'],
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
     * The signed-in account accepts (mode `join` only;
     * InvitationForAnotherAccount otherwise, also when the account follows
     * another owner here).
     *
     * @throws InvitationUnavailable
     */
    public function acceptAsExistingUser(#[SensitiveParameter] string $token, User $user): AcceptedOwnerInvitation
    {
        return $this->accept($token, $user, function (Invitation $invitation) use ($user): User {
            if ($this->mode($invitation, $user) !== 'join') {
                throw new InvitationForAnotherAccount;
            }

            $byEmail = $invitation->channel === InvitationChannel::Email;

            if ($this->lacksIdentifier($invitation, $user)) {
                // The account gains what the link reached; nobody else holds it (mode() checked).
                $user->forceFill($byEmail
                    ? ['email' => $invitation->email, 'email_verified_at' => now()]
                    : ['phone' => $invitation->phone]);

                try {
                    $user->save();
                } catch (UniqueConstraintViolationException) {
                    throw new InvitationForAnotherAccount;
                }
            } elseif ($byEmail && $user->email_verified_at === null) {
                // Opening the emailed link proves the address, as the verification link would.
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            return $user;
        });
    }

    /** The account that holds the address or number this link is bound to, if any. */
    public function holder(Invitation $invitation): ?User
    {
        return $invitation->channel === InvitationChannel::Email
            ? ($invitation->email !== null ? User::query()->where('email', $invitation->email)->first() : null)
            : ($invitation->phone !== null ? User::query()->where('phone', $invitation->phone)->first() : null);
    }

    private function lacksIdentifier(Invitation $invitation, User $user): bool
    {
        return $invitation->channel === InvitationChannel::Email ? $user->email === null : $user->phone === null;
    }

    /** Is the account already linked to another owner profile of the invitation's organization? */
    private function linkedToAnotherOwner(Invitation $invitation, User $user): bool
    {
        $organization = Organization::query()->findOrFail($invitation->organization_id);

        return $this->context->run($organization, static fn (): bool => OwnerProfile::query()->withTrashed()
            ->where('user_id', $user->id)
            ->whereKeyNot((int) $invitation->owner_profile_id)
            ->exists());
    }

    /**
     * @param  callable(Invitation, OwnerProfile): User  $account
     *
     * @throws InvitationUnavailable
     */
    private function accept(string $token, ?User $signedIn, callable $account): AcceptedOwnerInvitation
    {
        $organization = $this->organizationFor($token) ?? throw new InvitationUnavailable;

        return $this->context->run($organization, function () use ($token, $signedIn, $account, $organization): AcceptedOwnerInvitation {
            $blocked = $this->writes->blockedOrganization();

            if ($blocked !== null) {
                $this->writes->refuse($blocked);
            }

            return DB::transaction(function () use ($token, $signedIn, $account, $organization): AcceptedOwnerInvitation {
                $invitation = $this->invitation($token, lock: true);
                $owner = $invitation?->owner_profile_id !== null
                    ? OwnerProfile::query()->whereKey($invitation->owner_profile_id)->lockForUpdate()->first()
                    : null;

                if ($invitation === null || ! $invitation->isUsable() || $owner === null || $owner->user_id !== null) {
                    throw new InvitationUnavailable;
                }

                $user = $account($invitation, $owner);

                if ($this->linkedToAnotherOwner($invitation, $user)) {
                    throw new InvitationForAnotherAccount;
                }

                // The role the invitation carries, never a workspace one (ARCHITECTURE.md § 5).
                $this->links->link($owner, $user, $organization);

                $invitation->forceFill([
                    'status' => InvitationStatus::Accepted,
                    'accepted_at' => now(),
                    'accepted_by_user_id' => $user->id,
                ])->save();

                $verified = $invitation->channel === InvitationChannel::WhatsApp
                    && $invitation->phone !== null
                    && $this->phones->byWhatsAppLink($user, $invitation->phone);

                $this->accessLog->record('invitation_accepted', $user);
                $this->audit->record('owner.portal_access_granted', $owner, $user, "{$user->name} accepted the owner portal invitation", [
                    'invitation_id' => $invitation->id,
                    'channel' => $invitation->channel->value,
                    'user_id' => $user->id,
                    'new_account' => $signedIn === null,
                    'phone_verified' => $verified,
                    'invited_by_user_id' => $invitation->invited_by_user_id,
                ]);

                return new AcceptedOwnerInvitation($user, $organization, $owner, newAccount: $signedIn === null);
            });
        });
    }

    private function invitation(string $token, bool $lock = false): ?Invitation
    {
        $query = Invitation::query()->where('token_hash', MemberInvitations::hash($token))->where('kind', InvitationKind::Owner);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    /** The organization of the owner invitation with this token (one unscoped column read), if any. */
    private function organizationFor(string $token): ?Organization
    {
        if ($token === '' || strlen($token) > 128) {
            return null;
        }

        $id = DB::table('invitations')->where('token_hash', MemberInvitations::hash($token))->where('kind', InvitationKind::Owner->value)->value('organization_id');

        return is_numeric($id) ? Organization::query()->find((int) $id) : null;
    }
}
