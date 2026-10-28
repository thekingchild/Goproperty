<?php

declare(strict_types=1);

namespace App\Platform\Handovers;

use App\Platform\Customers\Customer;
use App\Policies\HandoverOverviewPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * The console's read model of a management handover, across organizations
 * (BILLING_PAYMENTS.md § 7.7 rule 3: "The platform console shows pending
 * and ending handovers", support visibility). Read-only: handovers change
 * only through the Handovers module's HandoverService, never from the
 * console. Reads the table directly (the console may read across
 * organizations; ARCHITECTURE.md § 4), with the building count from
 * `management_handover_buildings`.
 *
 * @property int $id
 * @property int $organization_id the offering organization (the landlord's; the agency's on a one-way return)
 * @property int $to_organization_id the receiving organization (the agency; the landlord's on a one-way return)
 * @property string $kind `management` or `return` (§ 7.1)
 * @property string $status
 * @property int $notice_days
 * @property int|null $buildings_count
 * @property int|null $units_count
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable|null $notice_given_at
 * @property CarbonImmutable|null $ends_at
 * @property-read Customer $landlord
 * @property-read Customer $agency
 */
#[UsePolicy(HandoverOverviewPolicy::class)]
final class HandoverOverview extends Model
{
    /** The states the console lists (§ 7.7 rule 3), and their labels. */
    public const array OPEN = ['pending' => 'Waiting for the agency', 'ending' => 'Ending (notice given)'];

    protected $table = 'management_handovers';

    protected static function booted(): void
    {
        self::saving(fn (): never => throw new LogicException('Handovers change only through the Handovers module\'s HandoverService.'));
        self::deleting(fn (): never => throw new LogicException('Handovers are never deleted.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'notice_days' => 'integer',
            'buildings_count' => 'integer',
            'units_count' => 'integer',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'notice_given_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function landlord(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'organization_id');
    }

    /** @return BelongsTo<Customer, $this> */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'to_organization_id');
    }

    public function statusLabel(): string
    {
        if ($this->kind === 'return' && $this->status === 'pending') {
            return 'Return waiting for the landlord';
        }

        return self::OPEN[$this->status] ?? ucfirst($this->status);
    }
}
