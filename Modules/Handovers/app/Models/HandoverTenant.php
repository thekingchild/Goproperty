<?php

declare(strict_types=1);

namespace Modules\Handovers\Models;

use App\Casts\MoneyCast;
use App\Support\Money;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Handovers\Enums\CreditSettlement;

/**
 * A tenant a management handover moves, and how their unused credit is
 * settled (`management_handover_tenants`, DATABASE_SCHEMA.md § 1; P03
 * decision 11). Organization-scoped like its handover (the offering
 * organization); written only by Handovers\HandoverService and
 * Handovers\HandoverCredits.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $management_handover_id
 * @property int $tenant_profile_id the tenant in the landlord's organization (their credit and bills stay there)
 * @property int|null $receiving_tenant_profile_id the tenant standing for them where the buildings went
 * @property string $tenant_name
 * @property Money $credit_kobo their credit when the handover was offered, then accepted
 * @property int $unpaid_bills bills still owed to the landlord then (they stay with it)
 * @property Money $unpaid_kobo
 * @property CreditSettlement $settlement the landlord's choice
 * @property Money|null $settled_kobo
 * @property CarbonImmutable|null $settled_at
 * @property int|null $settled_by_user_id
 * @property int|null $settled_by_organization_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read ManagementHandover $handover
 */
final class HandoverTenant extends Model
{
    use BelongsToOrganization;

    protected $table = 'management_handover_tenants';

    /** @var array<string, mixed> */
    protected $attributes = [
        'credit_kobo' => 0,
        'unpaid_bills' => 0,
        'unpaid_kobo' => 0,
        'settlement' => 'pass_to_agency',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'credit_kobo' => MoneyCast::class,
            'unpaid_bills' => 'integer',
            'unpaid_kobo' => MoneyCast::class,
            'settlement' => CreditSettlement::class,
            'settled_kobo' => MoneyCast::class,
            'settled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ManagementHandover, $this> */
    public function handover(): BelongsTo
    {
        return $this->belongsTo(ManagementHandover::class, 'management_handover_id');
    }

    public function isSettled(): bool
    {
        return $this->settled_at !== null;
    }
}
