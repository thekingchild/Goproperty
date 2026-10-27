<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InvitationChannel;
use App\Enums\InvitationKind;
use App\Enums\InvitationStatus;
use App\Enums\OrganizationRole;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An invitation into the current organization (DATABASE_SCHEMA.md § 1,
 * ARCHITECTURE.md § 6.1): a single-use link, valid 14 days, that can be
 * sent again or revoked (ONBOARDING_COMPLIANCE.md § 2). Organization-scoped
 * like every customer-domain model; the acceptance page finds its
 * organization from the token first (App\Members\InvitationAcceptance).
 * Changed only through App\Members\MemberInvitations and
 * InvitationAcceptance (kind `user`), the SaasBilling module's
 * BillingTransferService (kind `org_billing_transfer`, the link of a
 * billing transfer), and the Tenants module's Portal classes (kinds
 * `tenant` and `sign_in_link`, P03-01b); only the token's SHA-256 is
 * stored.
 *
 * @property int $id
 * @property int $organization_id
 * @property InvitationKind $kind
 * @property string|null $email
 * @property string|null $phone
 * @property int|null $user_id the account a `sign_in_link` opens (P03-01b)
 * @property int|null $tenant_profile_id the tenant a `tenant` invitation or `sign_in_link` is for (P03-01b)
 * @property list<string> $roles role names (OrganizationRole values)
 * @property int|null $target_organization_id
 * @property int|null $billing_transfer_id the billing transfer an `org_billing_transfer` invitation carries (P02-04)
 * @property string $token_hash
 * @property InvitationChannel $channel
 * @property InvitationStatus $status
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $sent_at
 * @property int $send_count
 * @property CarbonImmutable|null $opened_at
 * @property int|null $invited_by_user_id
 * @property CarbonImmutable|null $accepted_at
 * @property int|null $accepted_by_user_id
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Organization $organization
 * @property-read User|null $invitedBy
 */
final class Invitation extends Model
{
    use BelongsToOrganization;

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'kind' => 'user',
        'channel' => 'email',
        'status' => 'pending',
        'send_count' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => InvitationKind::class,
            'channel' => InvitationChannel::class,
            'status' => InvitationStatus::class,
            'roles' => 'array',
            'expires_at' => 'datetime',
            'sent_at' => 'datetime',
            'opened_at' => 'datetime',
            'accepted_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'send_count' => 'integer',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    /** @return list<OrganizationRole> */
    public function roleEnums(): array
    {
        return array_values(array_filter(array_map(
            static fn (string $name): ?OrganizationRole => OrganizationRole::tryFrom($name),
            $this->roles,
        )));
    }

    public function isExpired(): bool
    {
        return $this->expires_at === null || ! $this->expires_at->isFuture();
    }

    /** Pending and in date: the link still works. */
    public function isUsable(): bool
    {
        return $this->status === InvitationStatus::Pending && ! $this->isExpired();
    }

    /** What the members page shows: a pending invitation past its date is expired. */
    public function displayStatus(): InvitationStatus
    {
        return $this->status === InvitationStatus::Pending && $this->isExpired() ? InvitationStatus::Expired : $this->status;
    }
}
