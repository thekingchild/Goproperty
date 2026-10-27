<?php

declare(strict_types=1);

namespace App\Platform\Compliance;

use App\Audit\AuditEntry;
use App\Casts\MoneyCast;
use App\Enums\TierApplicationStatus;
use App\Enums\TransactionTier;
use App\Models\Organization;
use App\Models\PlatformStaff;
use App\Models\TierApplication;
use App\Platform\Customers\Customer;
use App\Policies\TierReviewPolicy;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * The console's read model of a Transaction Tier application, across
 * organizations (ARCHITECTURE.md § 4: cross-organization reads use read
 * models in the Platform namespace). Read-only: decisions go through
 * TierReview, which changes App\Models\TierApplication inside the
 * organization's context. Audit entries name it as the application itself
 * (getMorphClass), so its history is the application's.
 *
 * @property int $id
 * @property int $organization_id
 * @property TransactionTier $from_tier
 * @property TransactionTier $requested_tier
 * @property Money|null $declared_annual_volume_kobo
 * @property TierApplicationStatus $status
 * @property array{name?: string, email?: string, phone?: string}|null $compliance_contact
 * @property string|null $applicant_note
 * @property CarbonImmutable|null $submitted_at
 * @property int|null $reviewed_by_staff_id
 * @property string|null $review_note
 * @property CarbonImmutable|null $decided_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Customer $customer
 */
#[UsePolicy(TierReviewPolicy::class)]
class ReviewApplication extends Model
{
    protected $table = 'tier_applications';

    protected static function booted(): void
    {
        static::saving(fn (): never => throw new LogicException('ReviewApplication is a read model: decide through App\Platform\Compliance\TierReview.'));
        static::deleting(fn (): never => throw new LogicException('Tier applications are never deleted.'));
    }

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

    public function getMorphClass(): string
    {
        return (new TierApplication)->getMorphClass();
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'organization_id');
    }

    /** @return HasMany<ReviewDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(ReviewDocument::class, 'tier_application_id');
    }

    /** @return BelongsTo<PlatformStaff, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(PlatformStaff::class, 'reviewed_by_staff_id');
    }

    /** @return HasMany<AuditEntry, $this> */
    public function history(): HasMany
    {
        return $this->hasMany(AuditEntry::class, 'subject_id')->where('subject_type', $this->getMorphClass());
    }

    public function organization(): Organization
    {
        return Organization::query()->whereKey($this->organization_id)->firstOrFail();
    }
}
