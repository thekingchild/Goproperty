<?php

declare(strict_types=1);

namespace Modules\Tenants\Livewire;

use App\Documents\QrCode;
use App\Enums\InvitationChannel;
use App\Models\User;
use App\Tenancy\BlockedWhileImpersonating;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Tenants\Models\TenantProfile;
use Modules\Tenants\Portal\PhoneConfirmations;
use Modules\Tenants\Portal\PortalAccessRefused;
use Modules\Tenants\Portal\PortalLink;
use Modules\Tenants\Portal\SignInLinks;
use Modules\Tenants\Portal\TenantInvitations;

/**
 * The "Portal access" section of a tenant's record (TenantShow; P03-01b,
 * ONBOARDING_COMPLIANCE.md § 2 "Tenants"): invite the tenant by email, by
 * the agency's WhatsApp or with a link / QR code to show or print; send
 * the invitation again or revoke it; once the tenant has an account,
 * confirm their phone number in person and create (or revoke) a one-time
 * sign-in link. Everything goes through Modules\Tenants\Portal, which
 * checks TenantProfilePolicy::managePortalAccess again; the actions are
 * also refused here while GoProperty staff impersonate (they decide who
 * signs in to an account) and, through the central guard, in a suspended
 * organization.
 *
 * A link made for copy / QR is shown once (only its hash is stored): it is
 * kept in a locked property until the agency closes the panel. The tenant
 * id is locked and loaded through the organization scope on every request.
 */
final class TenantPortalAccess extends Component
{
    #[Locked]
    public int $organizationId;

    #[Locked]
    public int $tenantId;

    /** The link just made, to copy or show as a QR code; never stored. */
    #[Locked]
    public ?string $shareUrl = null;

    /** `invitation` or `sign-in`. */
    #[Locked]
    public ?string $shareKind = null;

    #[Locked]
    public ?string $notice = null;

    #[Locked]
    public ?string $problem = null;

    public function boot(OrganizationContext $context): void
    {
        abort_unless($context->has(), 404);
    }

    public function hydrate(OrganizationContext $context): void
    {
        abort_unless($context->id() === $this->organizationId, 404);
    }

    public function mount(OrganizationContext $context, int $tenant): void
    {
        $this->organizationId = $context->id();
        $profile = TenantProfile::query()->withTrashed()->findOrFail($tenant);
        $this->authorize('view', $profile);
        $this->tenantId = $profile->id;
    }

    #[BlockedWhileImpersonating]
    public function invite(string $channel, TenantInvitations $invitations): void
    {
        $tenant = $this->managedTenant();
        $via = $this->channel($channel);

        $this->attempt(fn () => $this->shown($invitations->invite($this->user(), $tenant, $via), 'invitation', match ($via) {
            InvitationChannel::Email => __('The invitation was emailed to :email.', ['email' => $tenant->email]),
            InvitationChannel::WhatsApp => __('The invitation was sent to :phone by WhatsApp.', ['phone' => $tenant->phone]),
            InvitationChannel::Link => __('Share this link with :name, or let them scan the QR code. It works once, for :days days.', ['name' => $tenant->first_name, 'days' => TenantInvitations::VALID_DAYS]),
        }));
    }

    #[BlockedWhileImpersonating]
    public function resend(string $channel, TenantInvitations $invitations): void
    {
        $tenant = $this->managedTenant();
        $via = $this->channel($channel);

        $this->attempt(fn () => $this->shown($invitations->resend($this->user(), $tenant, $via), 'invitation', match ($via) {
            InvitationChannel::Email => __('A new invitation was emailed to :email. The old link no longer works.', ['email' => $tenant->email]),
            InvitationChannel::WhatsApp => __('A new invitation was sent to :phone by WhatsApp. The old link no longer works.', ['phone' => $tenant->phone]),
            InvitationChannel::Link => __('Here is a new link. The old one no longer works. It works once, for :days days.', ['days' => TenantInvitations::VALID_DAYS]),
        }));
    }

    #[BlockedWhileImpersonating]
    public function revoke(TenantInvitations $invitations): void
    {
        $tenant = $this->managedTenant();

        $this->attempt(function () use ($invitations, $tenant): void {
            $invitations->revoke($this->user(), $tenant);
            $this->clearShare();
            $this->notice = __('The invitation was revoked. Its link no longer works.');
        });
    }

    #[BlockedWhileImpersonating]
    public function createSignInLink(string $channel, SignInLinks $links): void
    {
        $tenant = $this->managedTenant();
        $via = $this->channel($channel);

        $this->attempt(fn () => $this->shown($links->create($this->user(), $tenant, $via), 'sign-in', $via === InvitationChannel::WhatsApp
            ? __('A one-time sign-in link was sent to the tenant by WhatsApp. It works once, for :hours hours.', ['hours' => SignInLinks::VALID_HOURS])
            : __('Give this sign-in link only to :name. It works once, for :hours hours.', ['name' => $tenant->first_name, 'hours' => SignInLinks::VALID_HOURS])));
    }

    #[BlockedWhileImpersonating]
    public function revokeSignInLink(SignInLinks $links): void
    {
        $tenant = $this->managedTenant();

        $this->attempt(function () use ($links, $tenant): void {
            $links->revoke($this->user(), $tenant);
            $this->clearShare();
            $this->notice = __('The sign-in link was revoked. It no longer works.');
        });
    }

    #[BlockedWhileImpersonating]
    public function confirmPhone(PhoneConfirmations $confirmations): void
    {
        $tenant = $this->managedTenant();

        $this->attempt(function () use ($confirmations, $tenant): void {
            $confirmations->confirm($this->user(), $tenant);
            $this->notice = __('The phone number :phone is now confirmed, by you.', ['phone' => $tenant->phone]);
        });
    }

    /** Hides the link: it cannot be shown again (a new one can be made). */
    public function closeShare(): void
    {
        $this->clearShare();
    }

    public function render(OrganizationContext $context, TenantInvitations $invitations, SignInLinks $links): View
    {
        $tenant = $this->tenant();
        $this->authorize('view', $tenant);
        $account = $tenant->user_id !== null ? User::query()->find($tenant->user_id) : null;
        $canManage = $this->user()->can('managePortalAccess', $tenant);

        return view('tenants::livewire.tenant-portal-access', [
            'organization' => $context->get(),
            'tenant' => $tenant,
            'account' => $account,
            'confirmedBy' => $account?->phone_confirmed_by_user_id !== null ? User::query()->find($account->phone_confirmed_by_user_id) : null,
            'canManage' => $canManage,
            'invitation' => $account === null ? $invitations->open($tenant) : null,
            'invitationChannels' => $account === null && ! $tenant->trashed() ? $invitations->channels($tenant) : [],
            'signInLink' => $account !== null ? $links->open($tenant) : null,
            'signInRefusal' => $account !== null ? $links->refusal($tenant) : null,
            'signInChannels' => $account !== null ? $links->channels() : [],
            'qr' => $this->shareUrl !== null ? QrCode::dataUri($this->shareUrl) : null,
        ]);
    }

    /** @param  callable(): void  $action */
    private function attempt(callable $action): void
    {
        $this->notice = null;
        $this->problem = null;

        try {
            $action();
        } catch (PortalAccessRefused $refused) {
            $this->problem = $refused->getMessage();
        }
    }

    private function shown(PortalLink $link, string $kind, string $notice): void
    {
        $this->clearShare();

        if ($link->invitation->channel === InvitationChannel::Link) {
            $this->shareUrl = $link->url;
            $this->shareKind = $kind;
        }

        $this->notice = $notice;
    }

    private function clearShare(): void
    {
        $this->shareUrl = null;
        $this->shareKind = null;
    }

    private function channel(string $channel): InvitationChannel
    {
        return InvitationChannel::tryFrom($channel) ?? abort(404);
    }

    private function managedTenant(): TenantProfile
    {
        $tenant = $this->tenant();
        $this->authorize('managePortalAccess', $tenant);

        return $tenant;
    }

    private function tenant(): TenantProfile
    {
        return TenantProfile::query()->withTrashed()->findOrFail($this->tenantId);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
