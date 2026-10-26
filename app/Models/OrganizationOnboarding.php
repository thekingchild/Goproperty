<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\OnboardingStep;
use App\Enums\TransactionTier;
use App\Support\Money;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * The signup wizard's saved state for one organization
 * (ONBOARDING_COMPLIANCE.md § 2, DATABASE_SCHEMA.md § 1). Changed only
 * through App\Onboarding\OnboardingWizard.
 *
 * - `step`: where the owner is, so signing in again resumes there.
 * - `declared_annual_volume_kobo` and `requested_tier`: what the Tier step
 *   recorded. The organization keeps working at its approved tier
 *   (`organizations.transaction_tier`); a request for Tier 2 or 3 waits
 *   for its documents and review (P01-03).
 * - `completed_at`: the wizard is over; the dashboard checklist takes over.
 *
 * @property int $id
 * @property int $organization_id
 * @property int|null $started_by_user_id
 * @property OnboardingStep $step
 * @property Money|null $declared_annual_volume_kobo
 * @property TransactionTier|null $requested_tier
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class OrganizationOnboarding extends Model
{
    use BelongsToOrganization, LogsActivity;

    /** @var list<string> */
    protected $fillable = ['started_by_user_id', 'step', 'declared_annual_volume_kobo', 'requested_tier', 'completed_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'step' => OnboardingStep::class,
            'declared_annual_volume_kobo' => MoneyCast::class,
            'requested_tier' => TransactionTier::class,
            'completed_at' => 'datetime',
        ];
    }

    public function isComplete(): bool
    {
        return $this->completed_at !== null;
    }

    /** @return BelongsTo<User, $this> */
    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_user_id');
    }

    /** Audit trail (PLATFORM_CONSOLE.md § 7): the tier asked for and the volume declared matter to compliance. */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('onboarding')
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
