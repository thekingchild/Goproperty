<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ComplianceDocumentType;
use App\Enums\TierVersionStatus;
use App\Enums\TransactionTier;
use App\Policies\TransactionTierVersionPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One version of a Transaction Tier's terms (P06-08; ONBOARDING_COMPLIANCE.md
 * § 5.2, DATABASE_SCHEMA.md § 9 `transaction_tiers`). Platform data, not
 * organization-scoped. Read through App\Compliance\TransactionTiers; written
 * only by App\Platform\Compliance\TierSettings.
 *
 * @property int $id
 * @property TransactionTier $tier
 * @property int $version
 * @property string $name
 * @property int|null $annual_volume_limit_kobo
 * @property int|null $single_payment_limit_kobo
 * @property int|null $review_threshold_kobo
 * @property list<string> $required_documents
 * @property list<string> $optional_documents
 * @property int|null $review_sla_days
 * @property CarbonImmutable $effective_from
 * @property TierVersionStatus $status
 * @property string|null $reason
 * @property int|null $proposed_by_staff_id
 * @property int|null $approval_request_id
 * @property int|null $approved_by_staff_id
 * @property CarbonImmutable|null $approved_at
 * @property int|null $cancelled_by_staff_id
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read PlatformStaff|null $proposedBy
 * @property-read PlatformStaff|null $approvedBy
 * @property-read PlatformStaff|null $cancelledBy
 * @property-read ApprovalRequest|null $approvalRequest
 */
#[UsePolicy(TransactionTierVersionPolicy::class)]
class TransactionTierVersion extends Model
{
    protected $table = 'transaction_tiers';

    protected $guarded = ['*'];

    /** @return list<ComplianceDocumentType> */
    public function requiredDocuments(): array
    {
        return self::documentTypes($this->required_documents);
    }

    /** @return list<ComplianceDocumentType> */
    public function optionalDocuments(): array
    {
        return self::documentTypes($this->optional_documents);
    }

    /** Approved and its date has not come yet (Lagos). */
    public function isScheduled(): bool
    {
        return $this->status === TierVersionStatus::Approved
            && $this->effective_from->toDateString() > CarbonImmutable::now('Africa/Lagos')->toDateString();
    }

    /** @return BelongsTo<PlatformStaff, $this> */
    public function proposedBy(): BelongsTo
    {
        return $this->belongsTo(PlatformStaff::class, 'proposed_by_staff_id');
    }

    /** @return BelongsTo<PlatformStaff, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(PlatformStaff::class, 'approved_by_staff_id');
    }

    /** @return BelongsTo<PlatformStaff, $this> */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(PlatformStaff::class, 'cancelled_by_staff_id');
    }

    /** @return BelongsTo<ApprovalRequest, $this> */
    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class);
    }

    /**
     * Unknown values (a type removed from the code) are skipped.
     *
     * @param  array<mixed>|null  $values
     * @return list<ComplianceDocumentType>
     */
    private static function documentTypes(?array $values): array
    {
        $types = [];

        foreach ($values ?? [] as $value) {
            $type = is_string($value) ? ComplianceDocumentType::tryFrom($value) : null;

            if ($type !== null && ! in_array($type, $types, true)) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tier' => TransactionTier::class,
            'version' => 'integer',
            'annual_volume_limit_kobo' => 'integer',
            'single_payment_limit_kobo' => 'integer',
            'review_threshold_kobo' => 'integer',
            'required_documents' => 'array',
            'optional_documents' => 'array',
            'review_sla_days' => 'integer',
            'effective_from' => 'immutable_date',
            'status' => TierVersionStatus::class,
            'approved_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }
}
