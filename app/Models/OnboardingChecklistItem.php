<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ChecklistItem;
use App\Enums\ChecklistStatus;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One item of an organization's setup checklist (ONBOARDING_COMPLIANCE.md
 * § 2 step 7, DATABASE_SCHEMA.md § 1). Changed only through
 * App\Onboarding\OnboardingChecklist.
 *
 * @property int $id
 * @property int $organization_id
 * @property ChecklistItem $item
 * @property ChecklistStatus $status
 * @property array<string, mixed>|null $details
 * @property CarbonImmutable|null $completed_at
 * @property int|null $completed_by_user_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class OnboardingChecklistItem extends Model
{
    use BelongsToOrganization;

    /** @var list<string> */
    protected $fillable = ['item', 'status', 'details', 'completed_at', 'completed_by_user_id'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'pending'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'item' => ChecklistItem::class,
            'status' => ChecklistStatus::class,
            'details' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function isDone(): bool
    {
        return $this->status === ChecklistStatus::Done;
    }
}
