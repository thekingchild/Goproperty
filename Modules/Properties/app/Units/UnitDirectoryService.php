<?php

declare(strict_types=1);

namespace Modules\Properties\Units;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Modules\Properties\Assignments\AgentNarrowing;
use Modules\Properties\Contracts\UnitCounts;
use Modules\Properties\Contracts\UnitDirectory;
use Modules\Properties\Contracts\UnitSummary;
use Modules\Properties\Enums\PropertyStatus;
use Modules\Properties\Enums\UnitStatus;
use Modules\Properties\Models\Unit;

/** The Properties module's answer to Contracts\UnitDirectory, through the organization scope. */
final readonly class UnitDirectoryService implements UnitDirectory
{
    public function __construct(private Units $units, private AgentNarrowing $narrowing) {}

    public function find(int $unitId): ?UnitSummary
    {
        $unit = Unit::query()->with('property')->whereHas('property')->find($unitId);

        return $unit instanceof Unit ? self::summary($unit) : null;
    }

    public function findMany(array $unitIds): array
    {
        if ($unitIds === []) {
            return [];
        }

        return Unit::query()->with('property')->whereHas('property')->whereKey($unitIds)->get()
            ->mapWithKeys(fn (Unit $unit): array => [$unit->id => self::summary($unit)])
            ->all();
    }

    public function countsForProperty(int $propertyId): UnitCounts
    {
        return self::countsOf(Unit::query()->where('property_id', $propertyId));
    }

    public function counts(?User $for = null): UnitCounts
    {
        return self::countsOf($for === null ? self::billable() : $this->narrowing->units(self::billable(), $for));
    }

    public function countBillable(): int
    {
        return self::billable()->count();
    }

    public function setStatus(int $unitId, string $status, string $reason): UnitSummary
    {
        $value = UnitStatus::tryFrom($status) ?? throw new InvalidArgumentException("\"{$status}\" is not a unit status.");

        $this->units->setStatus($unitId, $value, $reason);

        return $this->find($unitId) ?? throw new LogicException('The unit vanished.');
    }

    public function lock(int $unitId): ?UnitSummary
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Lock a unit inside a database transaction.');
        }

        $unit = Unit::query()->with('property')->whereHas('property')->whereKey($unitId)->lockForUpdate()->first();

        return $unit instanceof Unit ? self::summary($unit) : null;
    }

    /**
     * Units not archived, in buildings that are not archived (nor removed by
     * an import rollback): BILLING_PAYMENTS.md § 1.4.
     *
     * @return Builder<Unit>
     */
    public static function billable(): Builder
    {
        return Unit::query()->whereHas('property', fn (Builder $query) => $query->where('status', PropertyStatus::Active));
    }

    /** @param  Builder<Unit>  $query */
    public static function countsOf(Builder $query): UnitCounts
    {
        $byStatus = [];

        foreach ($query->toBase()->reorder()->select('status')->selectRaw('count(*) as units_count')->groupBy('status')->get() as $row) {
            $byStatus[(string) $row->status] = (int) $row->units_count;
        }

        return UnitCounts::fromStatuses($byStatus);
    }

    private static function summary(Unit $unit): UnitSummary
    {
        $property = $unit->property;

        return new UnitSummary(
            id: $unit->id,
            organizationId: $unit->organization_id,
            propertyId: $unit->property_id,
            propertyName: $property->name ?? '',
            propertyArchived: $property?->isArchived() ?? false,
            label: $unit->unit_label,
            type: $unit->unit_type->value,
            typeLabel: $unit->unit_type->label(),
            status: $unit->status->value,
            statusLabel: $unit->status->label(),
            baseRent: $unit->base_rent_kobo,
            serviceCharge: $unit->service_charge_kobo,
        );
    }
}
