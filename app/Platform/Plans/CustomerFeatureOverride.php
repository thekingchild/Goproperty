<?php

declare(strict_types=1);

namespace App\Platform\Plans;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * The console's read model of `organization_feature_overrides` across
 * organizations (P06-07a): an organization's overrides, ended ones too, for
 * its page. Read-only: FeatureOverrides is the only writer.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $feature_key
 * @property bool $enabled
 * @property int|null $limit_value
 * @property string $reason
 * @property int|null $granted_by_staff_id
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $updated_at
 */
final class CustomerFeatureOverride extends Model
{
    protected $table = 'organization_feature_overrides';

    protected static function booted(): void
    {
        self::saving(fn (): never => throw new LogicException('CustomerFeatureOverride is a read model: overrides change only through FeatureOverrides.'));
        self::deleting(fn (): never => throw new LogicException('Feature overrides are ended, never deleted.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'limit_value' => 'integer', 'expires_at' => 'immutable_datetime'];
    }
}
