<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * An organization told about a plan version before it takes effect
 * (P06-01a; BILLING_PAYMENTS.md § 1.3). Written once per version and
 * organization by App\Platform\Plans\SendPlanChangeNotices before the
 * owners' emails are queued. Organization-scoped; never deleted.
 *
 * @property int $id
 * @property int $plan_version_id
 * @property int $organization_id
 * @property int $recipients
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class PlanChangeNotice extends Model
{
    use BelongsToOrganization;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        self::deleting(static fn (): never => throw new LogicException('Plan change notices are never deleted.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['recipients' => 'integer'];
    }
}
