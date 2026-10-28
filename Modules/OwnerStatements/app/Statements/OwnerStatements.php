<?php

declare(strict_types=1);

namespace Modules\OwnerStatements\Statements;

use Carbon\CarbonImmutable;
use Modules\Leasing\Contracts\EndingLease;
use Modules\Leasing\Contracts\OwnerLeases;
use Modules\Properties\Contracts\OwnerProfileDirectory;
use Modules\Properties\Contracts\PropertySummary;
use Modules\Properties\Contracts\UnitDirectory;
use Modules\Properties\Contracts\UnitSummary;
use Modules\Tenants\Contracts\TenantDirectory;

/**
 * What the statement pages and the PDF read (P08-05), inside the managing
 * organization: an owner's month — the settled snapshot when the agency
 * marked it settled, else worked out now (StatementBuilder) — a list of
 * months (one build for all the unsettled ones), and the upcoming renewals
 * of the owner's buildings. Reads only. Who may see it is the caller's
 * check (OwnerStatementPolicy for the agency, the owner portal's path for
 * the owner).
 */
final readonly class OwnerStatements
{
    /** Leases ending within this many days are "upcoming renewals". */
    public const int RENEWAL_DAYS = 90;

    public function __construct(
        private StatementBuilder $builder,
        private Settlements $settlements,
        private StatementTermsBook $terms,
        private OwnerProfileDirectory $owners,
        private OwnerLeases $leases,
        private UnitDirectory $units,
        private TenantDirectory $tenants,
    ) {}

    public function month(int $ownerProfileId, StatementMonth $month): MonthStatement
    {
        return $this->months($ownerProfileId, [$month])[0];
    }

    /**
     * @param  list<StatementMonth>  $months
     * @return list<MonthStatement> in the order given
     */
    public function months(int $ownerProfileId, array $months): array
    {
        $settled = $this->settlements->forMonths($ownerProfileId, $months);
        $live = $this->builder->build($ownerProfileId, array_values(array_filter(
            $months,
            static fn (StatementMonth $month): bool => ! (($settled[$month->key()] ?? null)?->isSettled() ?? false),
        )));

        return array_map(static function (StatementMonth $month) use ($settled, $live): MonthStatement {
            $row = $settled[$month->key()] ?? null;

            if ($row !== null && $row->isSettled() && is_array($row->figures)) {
                return new MonthStatement(OwnerStatement::fromArray($row->figures, $month), $row);
            }

            return new MonthStatement($live[$month->key()], $row);
        }, $months);
    }

    public function terms(int $ownerProfileId): StatementTerms
    {
        return $this->terms->of($ownerProfileId);
    }

    /**
     * Active leases of the owner's buildings ending from today to 90 days
     * from today (Lagos), soonest first, with their renewal state.
     *
     * @return list<RenewalLine>
     */
    public function renewals(int $ownerProfileId): array
    {
        $buildings = [];

        foreach ($this->owners->buildingsOf($ownerProfileId) as $building) {
            $buildings[$building->id] = $building;
        }

        $today = CarbonImmutable::parse(CarbonImmutable::now(StatementMonth::TIMEZONE)->toDateString());
        $ending = $this->leases->endingBetween(array_keys($buildings), $today, $today->addDays(self::RENEWAL_DAYS));

        if ($ending === []) {
            return [];
        }

        $shares = $this->terms->of($ownerProfileId)->shareTenantContacts;
        $units = $this->units->findMany(array_values(array_unique(array_map(static fn (EndingLease $lease): int => $lease->lease->unitId, $ending))));
        $tenants = $this->tenants->findMany(array_values(array_unique(array_map(static fn (EndingLease $lease): int => $lease->lease->tenantProfileId, $ending))));

        return array_map(static function (EndingLease $ending) use ($buildings, $units, $tenants, $shares): RenewalLine {
            $lease = $ending->lease;
            $tenant = $tenants[$lease->tenantProfileId] ?? null;
            $unit = $units[$lease->unitId] ?? null;
            $building = $buildings[$lease->propertyId] ?? null;

            return new RenewalLine(
                leaseId: $lease->id,
                building: $building instanceof PropertySummary ? $building->name : '',
                unit: $unit instanceof UnitSummary ? $unit->label : '',
                tenantName: $tenant->name ?? '',
                tenantPhone: $shares ? $tenant?->phone : null,
                tenantEmail: $shares ? $tenant?->email : null,
                endDate: $lease->endDate,
                rent: $lease->rent,
                cycleLabel: $lease->cycle->label(),
                state: $ending->renewalState(),
                stateLabel: $ending->renewalStateLabel(),
                renewalStart: $ending->renewal?->startDate,
            );
        }, $ending);
    }
}
