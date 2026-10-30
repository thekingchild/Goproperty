<?php

declare(strict_types=1);

namespace App\Models;

use App\Plans\Feature;
use App\Plans\FeatureValue;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One feature of a plan version (P06-01a; table `plan_feature`). Written
 * with its version by App\Platform\Plans\PlanEditor; never changed.
 *
 * @property int $id
 * @property int $plan_version_id
 * @property string $feature_key
 * @property bool $enabled
 * @property int|null $limit_value
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class PlanFeature extends Model
{
    protected $table = 'plan_feature';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        self::deleting(static fn (): never => throw new LogicException('Plan features are never deleted.'));
    }

    public function value(): ?FeatureValue
    {
        $feature = Feature::tryFrom($this->feature_key);

        return $feature === null ? null : FeatureValue::of($feature, $this->enabled, $this->limit_value);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'limit_value' => 'integer'];
    }
}
