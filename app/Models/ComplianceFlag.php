<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ComplianceFlagStatus;
use App\Enums\ComplianceFlagType;
use App\Enums\TransactionTier;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One thing the Transaction Tier monitoring noticed about an organization
 * (P06-09; ONBOARDING_COMPLIANCE.md § 5.4, DATABASE_SCHEMA.md § 9
 * `compliance_flags`). Organization-scoped. Raised only by
 * App\Compliance\Monitoring\ComplianceFlags; staff work it through
 * App\Platform\Compliance\FlagQueue (the console reads it across
 * organizations through App\Platform\Compliance\ReviewFlag). Never deleted.
 *
 * @property int $id
 * @property int $organization_id
 * @property ComplianceFlagType $type
 * @property TransactionTier|null $tier
 * @property string|null $subject_type `payment` or `compliance_document`
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
 * @property string $dedupe_key
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class ComplianceFlag extends Model
{
    use BelongsToOrganization;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Compliance flags are never deleted.'));
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /** @return BelongsTo<PlatformStaff, $this> */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(PlatformStaff::class, 'assigned_staff_id');
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
