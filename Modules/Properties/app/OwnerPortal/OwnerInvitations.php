<?php

declare(strict_types=1);

namespace Modules\Properties\OwnerPortal;

use App\Enums\InvitationChannel;
use App\Enums\InvitationKind;
use App\Enums\InvitationStatus;
use App\Enums\OrganizationRole;
use App\Members\MemberInvitations;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use App\WhatsApp\SendWhatsAppMessage;
use App\WhatsApp\WhatsAppMessage;
use App\WhatsApp\WhatsAppSender;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Modules\Properties\Models\OwnerProfile;
use Modules\Properties\Notifications\OwnerPortalInvitation;
use SensitiveParameter;

/**
 * Inviting a property owner to the owner portal (P03-15;
 * ONBOARDING_COMPLIANCE.md § 2 "Property owners", ARCHITECTURE.md § 6.1),
 * on the design of the tenant portal's invitations (P03-01b). The
 * invitation is a Core `invitations` row of kind `owner` pointing at the
 * owner (`owner_profile_id`), with roles `[property-owner]`: accepting
 * links the owner profile to a user, who joins the organization with that
 * role only (OwnerInvitationAcceptance) and so never opens the workspace.
 * The only writer of these rows besides the acceptance, and the only one
 * that takes an owner's access away (removeAccess).
 *
 * - Channels: `email` (to the owner's address), `whatsapp` (the agency's
 *   own WhatsApp through App\WhatsApp\WhatsAppSender, when it can send —
 *   a stand-in that sends nothing until P05-06, never offered in
 *   production; P03 decision 3; opening that link proves the phone
 *   number) and `link` (copy link or QR code the agency shares itself; the
 *   link is returned to show once). Both need the owner's phone number:
 *   the link is bound to it (no account takeover). Never SMS.
 * - Single use, valid 14 days; only the token's SHA-256 is stored. Sending
 *   again (any channel) makes a new link, the old one stops working, and
 *   restarts the 14 days. Revoking kills it at once (`cancelled`).
 * - One live invitation per owner; none for an owner who already has
 *   portal access or is archived.
 * - Every action checks OwnerProfilePolicy::managePortalAccess for the
 *   acting user (`owners.manage`; never while staff impersonate; the
 *   suspended guard refuses it as a write), is audited under log `owners`,
 *   and sends are limited per organization, per acting user and per owner.
 */
final readonly class OwnerInvitations
{
    public const int VALID_DAYS = 14;

    public const int PER_ORGANIZATION_HOURLY = 50;

    public const int PER_USER_HOURLY = 30;

    public const int PER_OWNER_DAILY = 5;

    public function __construct(
        private OrganizationContext $context,
        private WhatsAppSender $whatsapp,
        private OwnerAccessAudit $audit,
        private OwnerLinks $links,
    ) {}

    /**
     * The ways an invitation can reach this owner now.
     *
     * @return list<InvitationChannel>
     */
    public function channels(OwnerProfile $owner): array
    {
        $phone = $owner->phone !== null && $owner->phone !== '';

        return array_values(array_filter([
            $owner->email !== null && $owner->email !== '' ? InvitationChannel::Email : null,
            $phone && $this->whatsapp->available($this->context->get(), 'owner_invitation') ? InvitationChannel::WhatsApp : null,
            $phone ? InvitationChannel::Link : null,
        ]));
    }

    /** The owner's open invitation (in date or not), if any. */
    public function open(OwnerProfile $owner): ?Invitation
    {
        return Invitation::query()
            ->where('kind', InvitationKind::Owner)
            ->where('owner_profile_id', $owner->id)
            ->whereIn('status', [InvitationStatus::Pending, InvitationStatus::Expired])
            ->latest('id')
            ->first();
    }

    /** @throws OwnerAccessRefused */
    public function invite(User $actor, OwnerProfile $owner, InvitationChannel $channel): OwnerPortalLink
    {
        Gate::forUser($actor)->authorize('managePortalAccess', $owner);
        $organization = $this->context->get();

        return DB::transaction(function () use ($actor, $owner, $channel, $organization): OwnerPortalLink {
            $locked = $this->lockedOwner($owner);

            if ($this->open($locked) !== null) {
                throw new OwnerAccessRefused('This owner already has an invitation. Send it again or revoke it instead.');
            }

            $this->refuseChannel($locked, $channel);
            $this->throttle($actor, $locked);

            $token = self::newToken();
            $invitation = new Invitation;
            $invitation->forceFill([
                'kind' => InvitationKind::Owner,
                'owner_profile_id' => $locked->id,
                'channel' => $channel,
                'email' => $channel === InvitationChannel::Email ? $locked->email : null,
                'phone' => $locked->phone,
                'roles' => [OrganizationRole::PropertyOwner->value],
                'token_hash' => MemberInvitations::hash($token),
                'status' => InvitationStatus::Pending,
                'expires_at' => now()->addDays(self::VALID_DAYS),
                'sent_at' => now(),
                'send_count' => 1,
                'invited_by_user_id' => $actor->id,
            ])->save();

            $this->audit->record('owner.portal_invited', $locked, $actor, 'Invited to the owner portal', [
                'invitation_id' => $invitation->id,
                'channel' => $channel->value,
                'expires_at' => $invitation->expires_at?->toIso8601String(),
            ]);

            return $this->deliver($invitation, $token, $locked, $actor, $organization);
        });
    }

    /**
     * A new link for the owner's open invitation, by any channel: the old
     * link stops working and the 14 days start again.
     *
     * @throws OwnerAccessRefused
     */
    public function resend(User $actor, OwnerProfile $owner, InvitationChannel $channel): OwnerPortalLink
    {
        Gate::forUser($actor)->authorize('managePortalAccess', $owner);
        $organization = $this->context->get();

        return DB::transaction(function () use ($actor, $owner, $channel, $organization): OwnerPortalLink {
            $locked = $this->lockedOwner($owner);
            $open = $this->open($locked) ?? throw new OwnerAccessRefused('This owner has no invitation to send again. Invite them instead.');
            $invitation = Invitation::query()->whereKey($open->id)->lockForUpdate()->firstOrFail();

            $this->refuseChannel($locked, $channel);
            $this->throttle($actor, $locked);

            $token = self::newToken();
            $invitation->forceFill([
                'channel' => $channel,
                'email' => $channel === InvitationChannel::Email ? $locked->email : null,
                'phone' => $locked->phone,
                'token_hash' => MemberInvitations::hash($token),
                'status' => InvitationStatus::Pending,
                'expires_at' => now()->addDays(self::VALID_DAYS),
                'sent_at' => now(),
                'send_count' => $invitation->send_count + 1,
            ])->save();

            $this->audit->record('owner.portal_invitation_resent', $locked, $actor, 'Sent the owner portal invitation again', [
                'invitation_id' => $invitation->id,
                'channel' => $channel->value,
                'expires_at' => $invitation->expires_at?->toIso8601String(),
            ]);

            return $this->deliver($invitation, $token, $locked, $actor, $organization);
        });
    }

    /** @throws OwnerAccessRefused */
    public function revoke(User $actor, OwnerProfile $owner): Invitation
    {
        Gate::forUser($actor)->authorize('managePortalAccess', $owner);

        return DB::transaction(function () use ($actor, $owner): Invitation {
            $locked = $this->lockedOwner($owner, allowLinked: true);
            $open = $this->open($locked) ?? throw new OwnerAccessRefused('This owner has no invitation to revoke.');
            $invitation = Invitation::query()->whereKey($open->id)->lockForUpdate()->firstOrFail();

            $invitation->forceFill(['status' => InvitationStatus::Cancelled, 'cancelled_at' => now()])->save();

            $this->audit->record('owner.portal_invitation_revoked', $locked, $actor, 'Revoked the owner portal invitation', [
                'invitation_id' => $invitation->id,
                'channel' => $invitation->channel->value,
            ]);

            return $invitation;
        });
    }

    /**
     * Takes the owner's portal access away: the owner profile is no longer
     * linked to the account, and the account loses `property-owner` here
     * (and its membership when it holds no other role here). The account
     * itself, and anything it does elsewhere, stays. It can be invited again.
     *
     * @throws OwnerAccessRefused
     */
    public function removeAccess(User $actor, OwnerProfile $owner): void
    {
        Gate::forUser($actor)->authorize('managePortalAccess', $owner);
        $organization = $this->context->get();

        DB::transaction(function () use ($actor, $owner, $organization): void {
            $locked = $this->lockedOwner($owner, allowLinked: true);

            if ($locked->user_id === null) {
                throw new OwnerAccessRefused('This owner has no portal access to remove.');
            }

            $account = User::query()->find($locked->user_id);
            $membershipRemoved = $this->links->unlink($locked, $account instanceof User ? $account : null, $organization);

            $this->audit->record('owner.portal_access_removed', $locked, $actor, 'Removed the owner\'s portal access', [
                'user_id' => $account?->id,
                'membership_removed' => $membershipRemoved,
            ]);
        });
    }

    public static function newToken(): string
    {
        return Str::random(48);
    }

    /** @throws OwnerAccessRefused */
    private function lockedOwner(OwnerProfile $owner, bool $allowLinked = false): OwnerProfile
    {
        $locked = OwnerProfile::query()->withTrashed()->whereKey($owner->id)->lockForUpdate()->firstOrFail();

        if ($locked->trashed()) {
            throw new OwnerAccessRefused('This owner is archived. Restore them first.');
        }

        if (! $allowLinked && $locked->user_id !== null) {
            throw new OwnerAccessRefused('This owner already has portal access.');
        }

        return $locked;
    }

    /** @throws OwnerAccessRefused */
    private function refuseChannel(OwnerProfile $owner, InvitationChannel $channel): void
    {
        if (! in_array($channel, $this->channels($owner), true)) {
            throw new OwnerAccessRefused(match ($channel) {
                InvitationChannel::Email => 'Add the owner\'s email address first, or share a link instead.',
                InvitationChannel::WhatsApp => $owner->phone === null || $owner->phone === ''
                    ? 'Add the owner\'s phone number first.'
                    : 'WhatsApp is not connected for your organization yet. Share a link instead.',
                InvitationChannel::Link => 'Add the owner\'s phone number first: a shared link is tied to it.',
            });
        }
    }

    /** @throws OwnerAccessRefused */
    private function throttle(User $actor, OwnerProfile $owner): void
    {
        $limits = [
            'owner-invitations:organization:'.$this->context->id() => [self::PER_ORGANIZATION_HOURLY, 3600],
            'owner-invitations:user:'.$actor->id => [self::PER_USER_HOURLY, 3600],
            'owner-invitations:owner:'.$owner->id => [self::PER_OWNER_DAILY, 86400],
        ];

        foreach ($limits as $key => [$max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw new OwnerAccessRefused('Too many invitations have been sent. Try again later.');
            }
        }

        foreach ($limits as $key => [, $decay]) {
            RateLimiter::hit($key, $decay);
        }
    }

    private function deliver(Invitation $invitation, #[SensitiveParameter] string $token, OwnerProfile $owner, User $actor, Organization $organization): OwnerPortalLink
    {
        $url = route('owner.invitations.show', ['token' => $token]);
        $name = $owner->first_name ?? $owner->display_name;

        match ($invitation->channel) {
            InvitationChannel::Email => Notification::route('mail', (string) $invitation->email)->notify(new OwnerPortalInvitation(
                token: $token,
                organizationName: $organization->name,
                invitedBy: $actor->name,
                ownerName: $name,
                validDays: self::VALID_DAYS,
            )),
            InvitationChannel::WhatsApp => SendWhatsAppMessage::dispatch((int) $organization->id, new WhatsAppMessage(
                to: (string) $invitation->phone,
                event: 'owner_invitation',
                text: __(':name, :organization invites you to follow your buildings in the owner portal: units, occupancy, leases and bills. Open this link to set your password (it works once, for :days days): :url', [
                    'name' => $name, 'organization' => $organization->name, 'days' => self::VALID_DAYS, 'url' => $url,
                ]),
                variables: ['owner.first_name' => $name, 'organization.name' => $organization->name, 'invitation_link' => $url],
            )),
            InvitationChannel::Link => null,
        };

        return new OwnerPortalLink($invitation, $url);
    }
}
