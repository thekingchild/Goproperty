<?php

declare(strict_types=1);

namespace App\Platform\Compliance;

use App\Enums\ComplianceFlagStatus;
use App\Enums\ComplianceFlagType;
use App\Enums\TransactionTier;
use App\Models\ComplianceFlag;
use App\Models\PlatformStaff;
use App\Platform\Customers\Customer;
use App\Policies\ComplianceFlagPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * The console's read model of a compliance flag, across organizations
 * (P06-09; ARCHITECTURE.md § 4: cross-organization reads use read models in
 * the Platform namespace). Read-only: staff work flags through FlagQueue,
 * which changes App\Models\ComplianceFlag inside the organization's
 * context. Audit entries name it as the flag itself (getMorphClass).
 *
 * @property int $id
 * @property int $organization_id
 * @property ComplianceFlagType $type
 * @property TransactionTier|null $tier
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property int|null $tenant_profile_id
 * @property int|null $metric_value_kobo
 * @property int|null $limit_kobo
 * @property array<string, mixed>|null $details
 * @property ComplianceFlagStatus $status
 * @property int|null $assigned_staff_id
 * @property string|null $resolution_note
 * @property int|null $resolved_by_staff_id
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable|null $grace_ends_at
 * @property CarbonImmutable|null $owners_notified_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Customer $customer
 * @property-read PlatformStaff|null $assignedTo
 * @property-read PlatformStaff|null $resolvedBy
 */
#[UsePolicy(ComplianceFlagPolicy::class)]
class ReviewFlag extends Model
{
    protected $table = 'compliance_flags';

    protected static function booted(): void
    {
        static::saving(fn (): never => throw new LogicException('ReviewFlag is a read model: work flags through App\Platform\Compliance\FlagQueue.'));
        static::deleting(fn (): never => throw new LogicException('Compliance flags are never deleted.'));
    }

    public function getMorphClass(): string
    {
        return (new ComplianceFlag)->getMorphClass();
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'organization_id')->withTrashed();
    }

    /** @return BelongsTo<PlatformStaff, $this> */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(PlatformStaff::class, 'assigned_staff_id');
    }

    /** @return BelongsTo<PlatformStaff, $this> */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(PlatformStaff::class, 'resolved_by_staff_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => ComplianceFlagType::class,
            'tier' => TransactionTier::class,
            'subject_id' => 'integer',
            'tenant_profile_id' => 'integer',
            'metric_value_kobo' => 'integer',
            'limit_kobo' => 'integer',
            'details' => 'array',
            'status' => ComplianceFlagStatus::class,
            'resolved_at' => 'immutable_datetime',
            'grace_ends_at' => 'immutable_datetime',
            'owners_notified_at' => 'immutable_datetime',
        ];
    }
}
