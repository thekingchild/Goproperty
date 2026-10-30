<?php

declare(strict_types=1);

namespace App\Models;

use App\Plans\Feature;
use App\Plans\FeatureValue;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A feature granted or withheld for one organization outside its plan
 * (P06-01a; ARCHITECTURE.md § 6.10). Wins over the plan until it expires.
 * Organization-scoped; read by App\Plans\Features, granted by platform staff
 * (console screen in P06-07) through App\Platform\Plans\FeatureOverrides.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $feature_key
 * @property bool $enabled
 * @property int|null $limit_value
 * @property string $reason
 * @property int|null $granted_by_staff_id
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class OrganizationFeatureOverride extends Model
{
    use BelongsToOrganization;

    protected $guarded = ['*'];

    public function value(): ?FeatureValue
    {
        $feature = Feature::tryFrom($this->feature_key);

        return $feature === null ? null : FeatureValue::of($feature, $this->enabled, $this->limit_value);
    }

    public function inForce(): bool
    {
        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'limit_value' => 'integer', 'expires_at' => 'immutable_datetime'];
    }
}
