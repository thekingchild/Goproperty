<?php

declare(strict_types=1);

namespace Modules\Properties\Livewire;

use App\Documents\QrCode;
use App\Enums\InvitationChannel;
use App\Models\User;
use App\Tenancy\BlockedWhileImpersonating;
use App\Tenancy\OrganizationContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Properties\Models\OwnerProfile;
use Modules\Properties\OwnerPortal\OwnerAccessRefused;
use Modules\Properties\OwnerPortal\OwnerInvitations;
use Modules\Properties\OwnerPortal\OwnerPortalLink;

/**
 * The "Owner portal" section of an owner's page (OwnerProfileForm; P03-15):
 * invite the owner by email, by the agency's WhatsApp or with a link / QR
 * code to show or print; send the invitation again or revoke it; once the
 * owner has an account, see which account follows the buildings and
 * remove that access. Everything goes through OwnerPortal\OwnerInvitations,
 * which checks OwnerProfilePolicy::managePortalAccess (`owners.manage`)
 * again; the actions are also refused here while GoProperty staff
 * impersonate (they decide who signs in to the owner's records) and,
 * through the central guard, in a suspended organization. Members with
 * `owners.view` only see the state.
 *
 * A link made for copy / QR is shown once (only its hash is stored): it is
 * kept in a locked property until the member closes the panel. The owner
 * id is locked and loaded through the organization scope on every request.
 */
final class OwnerPortalAccess extends Component
{
    #[Locked]
    public int $organizationId;

    #[Locked]
    public int $ownerId;

    /** The link just made, to copy or show as a QR code; never stored. */
    #[Locked]
    public ?string $shareUrl = null;

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

    public function mount(OrganizationContext $context, int $owner): void
    {
        $this->organizationId = $context->id();
        $profile = OwnerProfile::query()->findOrFail($owner);
        $this->authorize('view', $profile);
        $this->ownerId = $profile->id;
    }

    #[BlockedWhileImpersonating]
    public function invite(string $channel, OwnerInvitations $invitations): void
    {
        $owner = $this->managedOwner();
        $via = $this->channel($channel);

        $this->attempt(fn () => $this->shown($invitations->invite($this->user(), $owner, $via), match ($via) {
            InvitationChannel::Email => __('The invitation was emailed to :email.', ['email' => $owner->email]),
            InvitationChannel::WhatsApp => __('The invitation was sent to :phone by WhatsApp.', ['phone' => $owner->phone]),
            InvitationChannel::Link => __('Share this link with :name, or let them scan the QR code. It works once, for :days days.', ['name' => $owner->display_name, 'days' => OwnerInvitations::VALID_DAYS]),
        }));
    }

    #[BlockedWhileImpersonating]
    public function resend(string $channel, OwnerInvitations $invitations): void
    {
        $owner = $this->managedOwner();
        $via = $this->channel($channel);

        $this->attempt(fn () => $this->shown($invitations->resend($this->user(), $owner, $via), match ($via) {
            InvitationChannel::Email => __('A new invitation was emailed to :email. The old link no longer works.', ['email' => $owner->email]),
            InvitationChannel::WhatsApp => __('A new invitation was sent to :phone by WhatsApp. The old link no longer works.', ['phone' => $owner->phone]),
            InvitationChannel::Link => __('Here is a new link. The old one no longer works. It works once, for :days days.', ['days' => OwnerInvitations::VALID_DAYS]),
        }));
    }

    #[BlockedWhileImpersonating]
    public function revoke(OwnerInvitations $invitations): void
    {
        $owner = $this->managedOwner();

        $this->attempt(function () use ($invitations, $owner): void {
            $invitations->revoke($this->user(), $owner);
            $this->shareUrl = null;
            $this->notice = __('The invitation was revoked. Its link no longer works.');
        });
    }

    #[BlockedWhileImpersonating]
    public function removeAccess(OwnerInvitations $invitations): void
    {
        $owner = $this->managedOwner();

        $this->attempt(function () use ($invitations, $owner): void {
            $invitations->removeAccess($this->user(), $owner);
            $this->shareUrl = null;
            $this->notice = __(':name no longer sees these buildings in the owner portal.', ['name' => $owner->display_name]);
        });
    }

    /** Hides the link: it cannot be shown again (a new one can be made). */
    public function closeShare(): void
    {
        $this->shareUrl = null;
    }

    public function render(OrganizationContext $context, OwnerInvitations $invitations): View
    {
        $owner = $this->owner();
        $this->authorize('view', $owner);
        $account = $owner->user_id !== null ? User::query()->find($owner->user_id) : null;

        return view('properties::livewire.owner-portal-access', [
            'organization' => $context->get(),
            'owner' => $owner,
            'account' => $account,
            'canManage' => $this->user()->can('managePortalAccess', $owner),
            'invitation' => $account === null ? $invitations->open($owner) : null,
            'invitationChannels' => $account === null && ! $owner->trashed() ? $invitations->channels($owner) : [],
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
        } catch (OwnerAccessRefused $refused) {
            $this->problem = $refused->getMessage();
        }
    }

    private function shown(OwnerPortalLink $link, string $notice): void
    {
        $this->shareUrl = $link->invitation->channel === InvitationChannel::Link ? $link->url : null;
        $this->notice = $notice;
    }

    private function channel(string $channel): InvitationChannel
    {
        return InvitationChannel::tryFrom($channel) ?? abort(404);
    }

    private function managedOwner(): OwnerProfile
    {
        $owner = $this->owner();
        $this->authorize('managePortalAccess', $owner);

        return $owner;
    }

    private function owner(): OwnerProfile
    {
        return OwnerProfile::query()->withTrashed()->findOrFail($this->ownerId);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
