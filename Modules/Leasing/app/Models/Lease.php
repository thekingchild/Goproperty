<?php

declare(strict_types=1);

namespace Modules\Leasing\Models;

use App\Casts\MoneyCast;
use App\Support\Money;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Leasing\Contracts\BillingCycle;
use Modules\Leasing\Enums\LeaseStatus;

/**
 * A lease: the allocation of a unit to a tenant, and its contract
 * (DATABASE_SCHEMA.md § 3 `leases`, ARCHITECTURE.md § 6.3).
 * Organization-scoped. Written only by Modules\Leasing\Leases\LeaseService
 * (AGENT.md § 3 rule 5): nothing here is mass assignable, and the status
 * changes only through the service's transitions (LeaseStatus).
 * `active_unit_id` is the database's (a generated column keeping one
 * active lease per unit) and is never written.
 *
 * The unit, its building and the tenant belong to other modules: the lease
 * keeps their ids only and reads them through Modules\Properties\Contracts
 * (UnitDirectory, PropertyDirectory) and Modules\Tenants\Contracts
 * (TenantDirectory), never their models (ARCHITECTURE.md § 8 rule 3).
 * Rent and deposit are kobo through MoneyCast.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $property_id
 * @property int $unit_id
 * @property int $tenant_profile_id
 * @property string|null $contract_number
 * @property BillingCycle $billing_cycle
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable $end_date
 * @property Money $rent_amount_kobo
 * @property Money $deposit_kobo
 * @property CarbonImmutable|null $paid_until
 * @property CarbonImmutable|null $next_billing_date
 * @property array<string, mixed>|null $other_charges
 * @property int $due_day_offset
 * @property bool $auto_renew
 * @property int|null $max_rent_payments
 * @property LeaseStatus $status
 * @property int|null $renewal_of_lease_id
 * @property CarbonImmutable|null $reserved_until
 * @property CarbonImmutable|null $activated_at
 * @property CarbonImmutable|null $expired_at
 * @property CarbonImmutable|null $terminated_at
 * @property string|null $termination_reason
 * @property CarbonImmutable|null $ending_soon_flagged_at
 * @property string|null $notes
 * @property int|null $document_media_id
 * @property int|null $created_by_user_id
 * @property int|null $import_batch_id
 * @property string|null $external_ref
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
final class Lease extends Model
{
    use BelongsToOrganization, SoftDeletes;

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'draft', 'deposit_kobo' => 0, 'due_day_offset' => 0, 'auto_renew' => false];

    /**
     * The lease this one renews.
     *
     * @return BelongsTo<self, $this>
     */
    public function renewalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'renewal_of_lease_id');
    }

    /**
     * Renewals of this lease (a draft waiting, or the one that followed it).
     *
     * @return HasMany<self, $this>
     */
    public function renewals(): HasMany
    {
        return $this->hasMany(self::class, 'renewal_of_lease_id');
    }

    /** @return HasMany<LeaseDepositEntry, $this> */
    public function depositEntries(): HasMany
    {
        return $this->hasMany(LeaseDepositEntry::class);
    }

    /** The contract number ("LS-ADR-2027-000012"), or "Draft lease #12" before activation. */
    public function reference(): string
    {
        return $this->contract_number ?? __('Draft lease #:id', ['id' => $this->id]);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'billing_cycle' => BillingCycle::class,
            'start_date' => 'immutable_date',
            'end_date' => 'immutable_date',
            'paid_until' => 'immutable_date',
            'next_billing_date' => 'immutable_date',
            'reserved_until' => 'immutable_date',
            'rent_amount_kobo' => MoneyCast::class,
            'deposit_kobo' => MoneyCast::class,
            'other_charges' => 'array',
            'due_day_offset' => 'integer',
            'auto_renew' => 'boolean',
            'max_rent_payments' => 'integer',
            'status' => LeaseStatus::class,
            'activated_at' => 'immutable_datetime',
            'expired_at' => 'immutable_datetime',
            'terminated_at' => 'immutable_datetime',
            'ending_soon_flagged_at' => 'immutable_datetime',
        ];
    }
}
