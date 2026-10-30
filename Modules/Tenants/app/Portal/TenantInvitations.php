<?php

declare(strict_types=1);

namespace Modules\Tenants\Portal;

use App\Enums\InvitationChannel;
use App\Enums\InvitationKind;
use App\Enums\InvitationStatus;
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
use Modules\Tenants\Models\TenantProfile;
use Modules\Tenants\Notifications\TenantPortalInvitation;
use SensitiveParameter;

/**
 * Inviting a tenant to the portal (ONBOARDING_COMPLIANCE.md § 2 "Tenants",
 * ARCHITECTURE.md § 6.1; P03-01b). The invitation is a Core `invitations`
 * row of kind `tenant` pointing at the tenant (`tenant_profile_id`), with
 * no roles: accepting links the tenant to a user and never adds an
 * `organization_user` row (P03 decision 4; PortalInvitationAcceptance).
 * The only writer of these rows besides the acceptance.
 *
 * - Channels: `email` (to the tenant's address), `whatsapp` (the agency's
 *   own WhatsApp through App\WhatsApp\WhatsAppSender, when available;
 *   opening that link proves the phone number) and `link` (copy link or QR
 *   code the agency shares itself; the link is returned to show once).
 *   Never SMS.
 * - Single use, valid 14 days; only the token's SHA-256 is stored. Sending
 *   again (any channel) makes a new link, the old one stops working, and
 *   restarts the 14 days. Revoking kills it at once (`cancelled`).
 * - One live invitation per tenant; none for a tenant who already has a
 *   portal account or is archived.
 * - Every action checks TenantProfilePolicy::managePortalAccess for the
 *   acting user (`tenants.portal_access`, agent narrowing; the suspended
 *   and impersonation guards apply through it), is audited under log
 *   `tenants`, and sends are limited per organization, per acting user and
 *   per tenant (ARCHITECTURE.md § 10).
 */
final readonly class TenantInvitations
{
    public const int VALID_DAYS = 14;

    public const int PER_ORGANIZATION_HOURLY = 50;

    public const int PER_USER_HOURLY = 30;

    public const int PER_TENANT_DAILY = 5;

    public function __construct(
        private OrganizationContext $context,
        private WhatsAppSender $whatsapp,
        private PortalAccessAudit $audit,
    ) {}

    /**
     * The ways an invitation can reach this tenant now.
     *
     * @return list<InvitationChannel>
     */
    public function channels(TenantProfile $tenant): array
    {
        return array_values(array_filter([
            $tenant->email !== null ? InvitationChannel::Email : null,
            $this->whatsapp->available($this->context->get(), 'tenant_invitation') ? InvitationChannel::WhatsApp : null,
            InvitationChannel::Link,
        ]));
    }

    /** The tenant's open invitation (in date or not), if any. */
    public function open(TenantProfile $tenant): ?Invitation
    {
        return Invitation::query()
            ->where('kind', InvitationKind::Tenant)
            ->where('tenant_profile_id', $tenant->id)
            ->whereIn('status', [InvitationStatus::Pending, InvitationStatus::Expired])
            ->latest('id')
            ->first();
    }

    /** @throws PortalAccessRefused */
    public function invite(User $actor, TenantProfile $tenant, InvitationChannel $channel): PortalLink
    {
        Gate::forUser($actor)->authorize('managePortalAccess', $tenant);
        $organization = $this->context->get();

        return DB::transaction(function () use ($actor, $tenant, $channel, $organization): PortalLink {
            $locked = $this->lockedTenant($tenant);

            if ($this->open($locked) !== null) {
                throw new PortalAccessRefused('This tenant already has an invitation. Send it again or revoke it instead.');
            }

            $this->refuseChannel($locked, $channel);
            $this->throttle($actor, $locked);

            $token = self::newToken();
            $invitation = new Invitation;
            $invitation->forceFill([
                'kind' => InvitationKind::Tenant,
                'tenant_profile_id' => $locked->id,
                'channel' => $channel,
                'email' => $channel === InvitationChannel::Email ? $locked->email : null,
                'phone' => $locked->phone,
                'roles' => [],
                'token_hash' => MemberInvitations::hash($token),
                'status' => InvitationStatus::Pending,
                'expires_at' => now()->addDays(self::VALID_DAYS),
                'sent_at' => now(),
                'send_count' => 1,
                'invited_by_user_id' => $actor->id,
            ])->save();

            $this->audit->record('tenant.portal_invited', $locked, $actor, 'Invited to the tenant portal', [
                'invitation_id' => $invitation->id,
                'channel' => $channel->value,
                'expires_at' => $invitation->expires_at?->toIso8601String(),
            ]);

            return $this->deliver($invitation, $token, $locked, $actor, $organization);
        });
    }

    /**
     * A new link for the tenant's open invitation, by any channel: the old
     * link stops working and the 14 days start again.
     *
     * @throws PortalAccessRefused
     */
    public function resend(User $actor, TenantProfile $tenant, InvitationChannel $channel): PortalLink
    {
        Gate::forUser($actor)->authorize('managePortalAccess', $tenant);
        $organization = $this->context->get();

        return DB::transaction(function () use ($actor, $tenant, $channel, $organization): PortalLink {
            $locked = $this->lockedTenant($tenant);
            $open = $this->open($locked) ?? throw new PortalAccessRefused('This tenant has no invitation to send again. Invite them instead.');
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

            $this->audit->record('tenant.portal_invitation_resent', $locked, $actor, 'Sent the portal invitation again', [
                'invitation_id' => $invitation->id,
                'channel' => $channel->value,
                'expires_at' => $invitation->expires_at?->toIso8601String(),
            ]);

            return $this->deliver($invitation, $token, $locked, $actor, $organization);
        });
    }

    /** @throws PortalAccessRefused */
    public function revoke(User $actor, TenantProfile $tenant): Invitation
    {
        Gate::forUser($actor)->authorize('managePortalAccess', $tenant);

        return DB::transaction(function () use ($actor, $tenant): Invitation {
            $locked = $this->lockedTenant($tenant, allowLinked: true);
            $open = $this->open($locked) ?? throw new PortalAccessRefused('This tenant has no invitation to revoke.');
            $invitation = Invitation::query()->whereKey($open->id)->lockForUpdate()->firstOrFail();

            $invitation->forceFill(['status' => InvitationStatus::Cancelled, 'cancelled_at' => now()])->save();

            $this->audit->record('tenant.portal_invitation_revoked', $locked, $actor, 'Revoked the portal invitation', [
                'invitation_id' => $invitation->id,
                'channel' => $invitation->channel->value,
            ]);

            return $invitation;
        });
    }

    public static function newToken(): string
    {
        return Str::random(48);
    }

    /** @throws PortalAccessRefused */
    private function lockedTenant(TenantProfile $tenant, bool $allowLinked = false): TenantProfile
    {
        $locked = TenantProfile::query()->withTrashed()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();

        if ($locked->trashed()) {
            throw new PortalAccessRefused('This tenant is archived. Restore them first.');
        }

        if (! $allowLinked && $locked->user_id !== null) {
            throw new PortalAccessRefused('This tenant already has a portal account.');
        }

        return $locked;
    }

    /** @throws PortalAccessRefused */
    private function refuseChannel(TenantProfile $tenant, InvitationChannel $channel): void
    {
        if (! in_array($channel, $this->channels($tenant), true)) {
            throw new PortalAccessRefused(match ($channel) {
                InvitationChannel::Email => 'Add the tenant\'s email address first, or share a link instead.',
                InvitationChannel::WhatsApp => 'WhatsApp is not connected for your organization yet. Share a link instead.',
                InvitationChannel::Link => 'This invitation cannot be sent that way.',
            });
        }
    }

    /** @throws PortalAccessRefused */
    private function throttle(User $actor, TenantProfile $tenant): void
    {
        $limits = [
            'tenant-invitations:organization:'.$this->context->id() => [self::PER_ORGANIZATION_HOURLY, 3600],
            'tenant-invitations:user:'.$actor->id => [self::PER_USER_HOURLY, 3600],
            'tenant-invitations:tenant:'.$tenant->id => [self::PER_TENANT_DAILY, 86400],
        ];

        foreach ($limits as $key => [$max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw new PortalAccessRefused('Too many invitations have been sent. Try again later.');
            }
        }

        foreach ($limits as $key => [, $decay]) {
            RateLimiter::hit($key, $decay);
        }
    }

    private function deliver(Invitation $invitation, #[SensitiveParameter] string $token, TenantProfile $tenant, User $actor, Organization $organization): PortalLink
    {
        $url = route('portal.invitations.show', ['token' => $token]);

        match ($invitation->channel) {
            InvitationChannel::Email => Notification::route('mail', (string) $invitation->email)->notify(new TenantPortalInvitation(
                token: $token,
                organizationName: $organization->name,
                invitedBy: $actor->name,
                tenantName: $tenant->first_name,
                validDays: self::VALID_DAYS,
            )),
            InvitationChannel::WhatsApp => SendWhatsAppMessage::dispatch((int) $organization->id, new WhatsAppMessage(
                to: (string) $invitation->phone,
                event: 'tenant_invitation',
                text: __(':name, :organization invites you to its tenant portal: see your rent, bills and receipts. Open this link to set your password (it works once, for :days days): :url', [
                    'name' => $tenant->first_name, 'organization' => $organization->name, 'days' => self::VALID_DAYS, 'url' => $url,
                ]),
                variables: ['tenant.first_name' => $tenant->first_name, 'organization.name' => $organization->name, 'invitation_link' => $url],
            )),
            InvitationChannel::Link => null,
        };

        return new PortalLink($invitation, $url);
    }
}
