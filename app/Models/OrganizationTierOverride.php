<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Custom Transaction Tier limits for one organization (P06-08;
 * ONBOARDING_COMPLIANCE.md § 5.2 "Per-organization override"): a yearly
 * and/or single-payment limit that applies instead of its tier's until
 * `expires_at`. Organization-scoped; read by App\Compliance\TransactionTiers,
 * set and ended by platform staff through App\Platform\Compliance\TierOverrides.
 *
 * @property int $id
 * @property int $organization_id
 * @property int|null $annual_volume_limit_kobo
 * @property int|null $single_payment_limit_kobo
 * @property string $reason
 * @property int|null $set_by_staff_id
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class OrganizationTierOverride extends Model
{
    use BelongsToOrganization;

    protected $guarded = ['*'];

    public function inForce(): bool
    {
        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'annual_volume_limit_kobo' => 'integer',
            'single_payment_limit_kobo' => 'integer',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
