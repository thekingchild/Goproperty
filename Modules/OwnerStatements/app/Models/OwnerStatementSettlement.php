<?php

declare(strict_types=1);

namespace Modules\OwnerStatements\Models;

use App\Media\HasOrganizationMedia;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\OwnerStatements\Enums\SettlementStatus;
use Spatie\MediaLibrary\HasMedia;

/**
 * The agency's Settled / Unsettled mark on one owner's month (P08-05;
 * DATABASE_SCHEMA.md § 4 `owner_statement_settlements`), natural key
 * (organization, owner profile, month = the month's first day). A settled
 * month keeps the statement as it was settled (`figures`, built by
 * Statements\OwnerStatement::toArray()) with the branding of the day, the
 * date paid and a reference; every later view and the PDF come from that
 * snapshot, so payments confirmed or reversed afterwards land in a later
 * month and never change it. `version` counts the times it was settled:
 * each settled statement's PDF is kept once (evidence) under its own key.
 * Organization-scoped; written only by Statements\Settlements; never
 * deleted.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $owner_profile_id
 * @property CarbonImmutable $month
 * @property SettlementStatus $status
 * @property CarbonImmutable|null $paid_on
 * @property string|null $reference
 * @property int|null $net_due_kobo
 * @property array<string, mixed>|null $figures
 * @property array<string, mixed>|null $branding_snapshot
 * @property int $version
 * @property CarbonImmutable|null $settled_at
 * @property int|null $settled_by_user_id
 * @property CarbonImmutable|null $unsettled_at
 * @property int|null $unsettled_by_user_id
 * @property string|null $unsettle_reason
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class OwnerStatementSettlement extends Model implements HasMedia
{
    use BelongsToOrganization, HasOrganizationMedia;

    protected $table = 'owner_statement_settlements';

    /** @var array<string, mixed> */
    protected $attributes = ['version' => 0];

    public function isSettled(): bool
    {
        return $this->status === SettlementStatus::Settled;
    }

    /** The key its kept PDF has in `issued_documents` ("owner-statement-v2"). */
    public function documentKey(): string
    {
        return 'owner-statement-v'.$this->version;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'owner_profile_id' => 'integer',
            'month' => 'immutable_date',
            'status' => SettlementStatus::class,
            'paid_on' => 'immutable_date',
            'net_due_kobo' => 'integer',
            'figures' => 'array',
            'branding_snapshot' => 'array',
            'version' => 'integer',
            'settled_at' => 'immutable_datetime',
            'settled_by_user_id' => 'integer',
            'unsettled_at' => 'immutable_datetime',
            'unsettled_by_user_id' => 'integer',
        ];
    }
}
