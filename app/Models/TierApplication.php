<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\ComplianceDocumentStatus;
use App\Enums\TierApplicationStatus;
use App\Enums\TransactionTier;
use App\Support\Money;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An organization's application for Transaction Tier 2 or 3
 * (ONBOARDING_COMPLIANCE.md § 5.3, DATABASE_SCHEMA.md § 9). Organization-
 * scoped. Changed only through App\Compliance\TierApplications (the
 * org-owner) and App\Platform\Compliance\TierReview (compliance staff);
 * every change is written to the audit log there. While it is open the
 * organization keeps working at its approved tier
 * (`organizations.transaction_tier`).
 *
 * @property int $id
 * @property int $organization_id
 * @property int|null $open_organization_id set while open: one open application per organization
 * @property TransactionTier $from_tier
 * @property TransactionTier $requested_tier
 * @property Money|null $declared_annual_volume_kobo
 * @property TierApplicationStatus $status
 * @property array{name?: string, email?: string, phone?: string}|null $compliance_contact Tier 3, encrypted
 * @property string|null $applicant_note the owner's answer to a request for more information
 * @property int|null $started_by_user_id
 * @property int|null $submitted_by_user_id
 * @property CarbonImmutable|null $submitted_at
 * @property int|null $reviewed_by_staff_id
 * @property string|null $review_note what compliance told the owner (more information, decision)
 * @property CarbonImmutable|null $decided_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class TierApplication extends Model
{
    use BelongsToOrganization;

    /** @var list<string> */
    protected $fillable = ['from_tier', 'requested_tier', 'declared_annual_volume_kobo', 'status', 'started_by_user_id'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'draft'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'from_tier' => TransactionTier::class,
            'requested_tier' => TransactionTier::class,
            'declared_annual_volume_kobo' => MoneyCast::class,
            'status' => TierApplicationStatus::class,
            'compliance_contact' => 'encrypted:array',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /** @return HasMany<ComplianceDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(ComplianceDocument::class);
    }

    /**
     * The documents that count: not removed, not rejected, not expired.
     *
     * @return HasMany<ComplianceDocument, $this>
     */
    public function currentDocuments(): HasMany
    {
        return $this->documents()->whereIn('status', [ComplianceDocumentStatus::Pending->value, ComplianceDocumentStatus::Accepted->value]);
    }

    /** @return BelongsTo<PlatformStaff, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(PlatformStaff::class, 'reviewed_by_staff_id');
    }
}
