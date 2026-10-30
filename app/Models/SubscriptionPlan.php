<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A GoProperty plan (P06-01a; table `plans`, BILLING_PAYMENTS.md § 1): what
 * never changes a bill. Its priced terms and features are PlanVersion rows.
 * Platform-wide (no organization). Read through App\Marketing\PlanCatalogue;
 * written only by App\Platform\Plans\PlanEditor. Never deleted.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string $audience
 * @property list<string>|null $highlights
 * @property bool $featured
 * @property bool $custom
 * @property int $trial_days
 * @property bool $is_active
 * @property int $sort_order
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class SubscriptionPlan extends Model
{
    protected $table = 'plans';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        self::deleting(static fn (): never => throw new LogicException('Plans are never deleted; stop offering them instead.'));
    }

    /** @return HasMany<PlanVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(PlanVersion::class, 'plan_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'highlights' => 'array',
            'featured' => 'boolean',
            'custom' => 'boolean',
            'trial_days' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
