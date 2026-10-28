<?php

declare(strict_types=1);

namespace Modules\Handovers\Models;

use App\Casts\MoneyCast;
use App\Models\Organization;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Handovers\Enums\HandoverStatus;
use Modules\Handovers\Enums\RentCollection;

/**
 * A management handover (DATABASE_SCHEMA.md § 1, BILLING_PAYMENTS.md § 7):
 * a landlord's buildings handed to an agency for management, and back.
 *
 * Organization-scoped to the offering organization (`organization_id`).
 * The receiving organization (`to_organization_id`) never queries this
 * model in its own context: Handovers\IncomingHandovers reads the ids of
 * the rows addressed to it, then loads each one inside the offering
 * organization's context (P02 decision 2, as P01-04 does for invitation
 * links). No scope is removed anywhere.
 *
 * Every change goes through Handovers\HandoverService (AGENT.md § 3
 * rule 5); nothing is mass-assignable.
 *
 * @property int $id
 * @property int $organization_id the offering organization
 * @property int $to_organization_id the organization asked to manage the buildings
 * @property int $landlord_organization_id where the buildings return when management ends
 * @property HandoverStatus $status
 * @property RentCollection $rent_collected_by
 * @property int $notice_days 0–180
 * @property string|null $plan_key the receiving organization's plan when it accepted
 * @property int|null $billable_units handed-over units its bill counts, recorded on acceptance
 * @property Money|null $monthly_before_kobo the receiving organization's monthly bill before (before VAT)
 * @property Money|null $monthly_after_kobo … and after the handed-over units, as accepted
 * @property int|null $owner_profile_id the landlord's owner profile in the receiving organization
 * @property int|null $initiated_by_user_id the landlord's owner who offered (linked as the buildings' owner there)
 * @property int|null $responded_by_user_id
 * @property CarbonImmutable|null $responded_at accepted or declined
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable|null $expires_at 14 days after the offer
 * @property CarbonImmutable|null $accepted_at
 * @property int|null $notice_given_by_organization_id
 * @property int|null $notice_given_by_user_id
 * @property CarbonImmutable|null $notice_given_at
 * @property CarbonImmutable|null $ends_at when the buildings go back (notice given + notice days)
 * @property CarbonImmutable|null $ended_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Organization $organization
 * @property-read Organization $toOrganization
 * @property-read Organization $landlordOrganization
 * @property-read User|null $initiatedBy
 * @property-read User|null $respondedBy
 * @property-read Collection<int, HandoverBuilding> $buildings
 * @property-read Collection<int, HandoverTenant> $tenants
 */
final class ManagementHandover extends Model
{
    use BelongsToOrganization;

    protected $table = 'management_handovers';

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
        'rent_collected_by' => 'agency',
        'notice_days' => 30,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => HandoverStatus::class,
            'rent_collected_by' => RentCollection::class,
            'notice_days' => 'integer',
            'billable_units' => 'integer',
            'monthly_before_kobo' => MoneyCast::class,
            'monthly_after_kobo' => MoneyCast::class,
            'responded_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'notice_given_at' => 'datetime',
            'ends_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function toOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'to_organization_id');
    }

    /** @return BelongsTo<Organization, $this> */
    public function landlordOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'landlord_organization_id');
    }

    /** @return BelongsTo<User, $this> */
    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function respondedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by_user_id');
    }

    /** @return HasMany<HandoverBuilding, $this> */
    public function buildings(): HasMany
    {
        return $this->hasMany(HandoverBuilding::class)->orderBy('property_name')->orderBy('id');
    }

    /** @return HasMany<HandoverTenant, $this> */
    public function tenants(): HasMany
    {
        return $this->hasMany(HandoverTenant::class)->orderBy('tenant_name')->orderBy('id');
    }

    /** Past its 14 days (whether or not the expiry has been recorded yet). */
    public function isPastExpiry(): bool
    {
        return $this->expires_at === null || ! $this->expires_at->isFuture();
    }

    /** Pending and in date: it can still be accepted, declined or withdrawn. */
    public function isAwaitingAnswer(): bool
    {
        return $this->status === HandoverStatus::Pending && ! $this->isPastExpiry();
    }

    /** The status as the pages show it: a pending offer past its date reads "Expired". */
    public function shownStatus(): HandoverStatus
    {
        return $this->status === HandoverStatus::Pending && $this->isPastExpiry() ? HandoverStatus::Expired : $this->status;
    }

    /** The organization on the other side from $organizationId. */
    public function otherSideOf(int $organizationId): int
    {
        return $organizationId === $this->organization_id ? $this->to_organization_id : $this->organization_id;
    }

    /** @return list<int> */
    public function propertyIds(): array
    {
        return array_values($this->buildings->pluck('property_id')->map(static fn (mixed $id): int => (int) $id)->all());
    }

    public function unitCount(): int
    {
        return (int) $this->buildings->sum('unit_count');
    }
}
