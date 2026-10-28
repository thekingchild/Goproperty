<?php

declare(strict_types=1);

namespace Modules\OwnerStatements\Statements;

use Carbon\CarbonImmutable;
use Modules\OwnerStatements\Contracts\SettledMonths;
use Modules\OwnerStatements\Contracts\TenantContactSharing;
use Modules\OwnerStatements\Enums\SettlementStatus;
use Modules\OwnerStatements\Models\OwnerStatementSettlement;
use Modules\Properties\Contracts\PropertyDirectory;

/** The module's answers to Contracts\SettledMonths and Contracts\TenantContactSharing (P08-05). Reads only. */
final readonly class SettledMonthRecords implements SettledMonths, TenantContactSharing
{
    public function __construct(
        private PropertyDirectory $buildings,
        private StatementTermsBook $terms,
    ) {}

    public function settledMonth(int $propertyId, CarbonImmutable $day): ?string
    {
        $owner = $this->buildings->find($propertyId)?->ownerProfileId;

        if ($owner === null) {
            return null;
        }

        $month = StatementMonth::of($day->year, $day->month);

        return OwnerStatementSettlement::query()
            ->where('owner_profile_id', $owner)
            ->whereDate('month', $month->firstDay()->toDateString())
            ->where('status', SettlementStatus::Settled)
            ->exists() ? $month->label() : null;
    }

    public function sharesWith(int $ownerProfileId): bool
    {
        return $this->terms->of($ownerProfileId)->shareTenantContacts;
    }
}
