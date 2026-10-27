<?php

declare(strict_types=1);

namespace Modules\Tenants\Portal;

use App\Audit\AccessLog;
use App\Auth\PhoneVerification;
use App\Enums\InvitationChannel;
use App\Enums\InvitationKind;
use App\Enums\InvitationStatus;
use App\Members\InvitationForAnotherAccount;
use App\Members\InvitationUnavailable;
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
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Modules\Tenants\Models\TenantProfile;
use SensitiveParameter;

/**
 * The agency's one-time sign-in link for a tenant who cannot sign in
 * (ONBOARDING_COMPLIANCE.md § 2: a phone-only tenant who forgot their
 * password asks the agency; P03 decision 4). A Core `invitations` row of
 * kind `sign_in_link` for the tenant's account (`user_id`) and the tenant
 * (`tenant_profile_id`): only the token's SHA-256 is stored, it works once
 * and for 24 hours, making a new one revokes the last, and creating, using
 * and revoking are audited (and creating also goes to the authentication
 * log, `agency_signin_link_created`). Shared as a link or QR code, or sent
 * by the agency's WhatsApp when available (opening it then verifies the
 * number, like an invitation). Never SMS.
 *
 * **Whose account an agency may open.** A sign-in link signs in without the
 * password, so an agency gets one only for an account that is nothing but
 * this organization's tenant: phone-only (an account with an email address
 * resets its own password), without two-factor authentication (a link
 * never skips it), not a member of any organization's workspace, and not a
 * tenant of another organization (whose records the link would open too).
 * Anything else is refused (refusal()); the rules are checked again when
 * the link is used.
 *
 * Opening the link (GET) changes nothing, so link previews cannot use it
 * up; the person confirms, may choose a new password there, and is signed
 * in. Someone signed in with another account is refused.
 */
final readonly class SignInLinks
{
    public const int VALID_HOURS = 24;

    public const int PER_ORGANIZATION_HOURLY = 30;

    public const int PER_USER_HOURLY = 20;

    public const int PER_TENANT_DAILY = 5;

    public function __construct(
        private OrganizationContext $context,
        private WhatsAppSender $whatsapp,
        private PortalAccessAudit $audit,
        private AccessLog $accessLog,
        private LinkedTenantProfiles $linked,
        private PhoneVerification $phones,
    ) {}

    /** Why no sign-in link can be made for this tenant now, or null when one can. */
    public function refusal(TenantProfile $tenant): ?string
    {
        $account = $tenant->user_id !== null ? User::query()->find($tenant->user_id) : null;

        return match (true) {
            $account === null => 'This tenant has no portal account yet. Invite them instead.',
            $account->email !== null => 'This tenant\'s account has an email address, so they reset their password themselves with "Forgot password".',
            $account->phone === null => 'This tenant\'s account has no phone number to sign in with.',
            $account->two_factor_confirmed_at !== null => 'This account uses two-factor authentication, which a sign-in link never skips. The tenant can sign in with a recovery code.',
            $account->organizations()->exists() => 'This account also works in an organization\'s workspace, so only its owner can reset it.',
            $this->linked->elsewhere($account, (int) $tenant->organization_id) => 'This account is also a tenant of another organization, so a sign-in link would open more than your records. Only its owner can reset it.',
            default => null,
        };
    }

    /**
     * The ways a sign-in link can reach this tenant.
     *
     * @return list<InvitationChannel>
     */
    public function channels(): array
    {
        return array_values(array_filter([
            $this->whatsapp->available($this->context->get()) ? InvitationChannel::WhatsApp : null,
            InvitationChannel::Link,
        ]));
    }

    /** The tenant's live sign-in link, if any. */
    public function open(TenantProfile $tenant): ?Invitation
    {
        return Invitation::query()
            ->where('kind', InvitationKind::SignInLink)
            ->where('tenant_profile_id', $tenant->id)
            ->where('status', InvitationStatus::Pending)
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();
    }

    /** @throws PortalAccessRefused */
    public function create(User $actor, TenantProfile $tenant, InvitationChannel $channel): PortalLink
    {
        Gate::forUser($actor)->authorize('managePortalAccess', $tenant);
        $organization = $this->context->get();

        return DB::transaction(function () use ($actor, $tenant, $channel, $organization): PortalLink {
            $locked = TenantProfile::query()->whereKey($tenant->id)->lockForUpdate()->first()
                ?? throw new PortalAccessRefused('This tenant is archived. Restore them first.');

            $refusal = $this->refusal($locked);

            if ($refusal !== null) {
                throw new PortalAccessRefused($refusal);
            }

            if (! in_array($channel, $this->channels(), true)) {
                throw new PortalAccessRefused('WhatsApp is not connected for your organization yet. Share a link instead.');
            }

            $this->throttle($actor, $locked);
            $account = User::query()->findOrFail($locked->user_id);

            // One live link per tenant: a new one replaces the last.
            $replaced = Invitation::query()
                ->where('kind', InvitationKind::SignInLink)
                ->where('tenant_profile_id', $locked->id)
                ->where('status', InvitationStatus::Pending)
                ->lockForUpdate()
                ->get();

            foreach ($replaced as $old) {
                $old->forceFill(['status' => InvitationStatus::Cancelled, 'cancelled_at' => now()])->save();
            }

            $token = TenantInvitations::newToken();
            $link = new Invitation;
            $link->forceFill([
                'kind' => InvitationKind::SignInLink,
                'tenant_profile_id' => $locked->id,
                'user_id' => $account->id,
                'channel' => $channel,
                'phone' => $account->phone,
                'roles' => [],
                'token_hash' => MemberInvitations::hash($token),
                'status' => InvitationStatus::Pending,
                'expires_at' => now()->addHours(self::VALID_HOURS),
                'sent_at' => now(),
                'send_count' => 1,
                'invited_by_user_id' => $actor->id,
            ])->save();

            $this->accessLog->record('agency_signin_link_created', $account);
            $this->audit->record('tenant.sign_in_link_created', $locked, $actor, 'Created a one-time sign-in link', [
                'invitation_id' => $link->id,
                'user_id' => $account->id,
                'channel' => $channel->value,
                'expires_at' => $link->expires_at?->toIso8601String(),
                'replaced_invitation_ids' => $replaced->pluck('id')->all(),
            ]);

            $url = route('portal.sign-in-links.show', ['token' => $token]);

            if ($channel === InvitationChannel::WhatsApp) {
                SendWhatsAppMessage::dispatch((int) $organization->id, new WhatsAppMessage(
                    to: (string) $account->phone,
                    event: 'tenant_sign_in_link',
                    text: __(':organization sent you a link to sign in to your tenant portal. It works once, for :hours hours: :url', [
                        'organization' => $organization->name, 'hours' => self::VALID_HOURS, 'url' => $url,
                    ]),
                    variables: ['tenant.first_name' => $locked->first_name, 'organization.name' => $organization->name, 'sign_in_link' => $url],
                ));
            }

            return new PortalLink($link, $url);
        });
    }

    /** @throws PortalAccessRefused */
    public function revoke(User $actor, TenantProfile $tenant): Invitation
    {
        Gate::forUser($actor)->authorize('managePortalAccess', $tenant);

        return DB::transaction(function () use ($actor, $tenant): Invitation {
            $open = $this->open($tenant) ?? throw new PortalAccessRefused('This tenant has no sign-in link to revoke.');
            $link = Invitation::query()->whereKey($open->id)->lockForUpdate()->firstOrFail();
            $link->forceFill(['status' => InvitationStatus::Cancelled, 'cancelled_at' => now()])->save();

            $this->audit->record('tenant.sign_in_link_revoked', $tenant, $actor, 'Revoked the one-time sign-in link', [
                'invitation_id' => $link->id,
            ]);

            return $link;
        });
    }

    /** The usable link, or null (unknown, used, revoked, expired, or the account may no longer be opened this way). */
    public function find(#[SensitiveParameter] string $token): ?OpenedInvitation
    {
        $organization = $this->organizationFor($token);

        if ($organization === null) {
            return null;
        }

        return $this->context->run($organization, function () use ($token, $organization): ?OpenedInvitation {
            $link = $this->link($token);

            if ($link === null) {
                return null;
            }

            if ($link->status === InvitationStatus::Pending && $link->isExpired()) {
                $link->forceFill(['status' => InvitationStatus::Expired])->save();
            }

            $tenant = $this->usableTenant($link);

            if ($tenant === null) {
                return null;
            }

            if ($link->opened_at === null) {
                $link->forceFill(['opened_at' => now()])->save();
            }

            return new OpenedInvitation($link, $tenant, $organization);
        });
    }

    /**
     * Uses the link: the account it opens, to sign in. A new password, when
     * given, replaces the old one (signup rules).
     *
     * @param  array<string, mixed>  $input  password (optional)
     *
     * @throws InvitationUnavailable
     * @throws InvitationForAnotherAccount when someone else is signed in
     */
    public function use(#[SensitiveParameter] string $token, ?User $signedIn, #[SensitiveParameter] array $input): User
    {
        $data = Validator::make($input, [
            'password' => ['nullable', 'string', 'max:255', Password::default()],
        ], attributes: ['password' => 'new password'])->validate();

        $organization = $this->organizationFor($token) ?? throw new InvitationUnavailable;

        return $this->context->run($organization, fn (): User => DB::transaction(function () use ($token, $signedIn, $data): User {
            $link = $this->link($token, lock: true) ?? throw new InvitationUnavailable;
            $tenant = $this->usableTenant($link) ?? throw new InvitationUnavailable;
            $account = User::query()->lockForUpdate()->findOrFail($link->user_id);

            if ($signedIn !== null && ! $signedIn->is($account)) {
                throw new InvitationForAnotherAccount;
            }

            $link->forceFill([
                'status' => InvitationStatus::Accepted,
                'accepted_at' => now(),
                'accepted_by_user_id' => $account->id,
            ])->save();

            $newPassword = is_string($data['password'] ?? null) && $data['password'] !== '';

            if ($newPassword) {
                $account->forceFill(['password' => (string) $data['password']])->save();
            }

            $verified = $link->channel === InvitationChannel::WhatsApp
                && $link->phone !== null
                && $this->phones->byWhatsAppLink($account, $link->phone);

            $this->audit->record('tenant.sign_in_link_used', $tenant, $account, 'Signed in with a one-time sign-in link', [
                'invitation_id' => $link->id,
                'channel' => $link->channel->value,
                'password_changed' => $newPassword,
                'phone_verified' => $verified,
                'created_by_user_id' => $link->invited_by_user_id,
            ]);

            return $account;
        }));
    }

    /** The link's tenant when the link is usable and still allowed to open its account. */
    private function usableTenant(Invitation $link): ?TenantProfile
    {
        if (! $link->isUsable() || $link->user_id === null || $link->tenant_profile_id === null) {
            return null;
        }

        $tenant = TenantProfile::query()->find($link->tenant_profile_id);

        return $tenant !== null && $tenant->user_id === $link->user_id && $this->refusal($tenant) === null ? $tenant : null;
    }

    private function link(string $token, bool $lock = false): ?Invitation
    {
        $query = Invitation::query()->where('token_hash', MemberInvitations::hash($token))->where('kind', InvitationKind::SignInLink);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    private function organizationFor(string $token): ?Organization
    {
        if ($token === '' || strlen($token) > 128) {
            return null;
        }

        $id = DB::table('invitations')->where('token_hash', MemberInvitations::hash($token))->where('kind', InvitationKind::SignInLink->value)->value('organization_id');

        return is_numeric($id) ? Organization::query()->find((int) $id) : null;
    }

    /** @throws PortalAccessRefused */
    private function throttle(User $actor, TenantProfile $tenant): void
    {
        $limits = [
            'sign-in-links:organization:'.$this->context->id() => [self::PER_ORGANIZATION_HOURLY, 3600],
            'sign-in-links:user:'.$actor->id => [self::PER_USER_HOURLY, 3600],
            'sign-in-links:tenant:'.$tenant->id => [self::PER_TENANT_DAILY, 86400],
        ];

        foreach ($limits as $key => [$max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw new PortalAccessRefused('Too many sign-in links have been made. Try again later.');
            }
        }

        foreach ($limits as $key => [, $decay]) {
            RateLimiter::hit($key, $decay);
        }
    }
}
