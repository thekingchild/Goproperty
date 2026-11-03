<?php

declare(strict_types=1);

namespace Modules\Reports\Reports;

use App\Support\Money;
use Carbon\CarbonImmutable;
use Modules\Reports\Enums\AgingBucket;

/**
 * Arrears aging as of today in Lagos (P08-01; docs/plans/P08-subtasks.md):
 * every bill still asking for money (unpaid, partly paid or overdue; void
 * bills never) whose due date is today or earlier, by what it still owes,
 * in buckets of days past the due date (0–30, 31–60, 61–90, 90+). Bills
 * not yet due are counted apart ("not yet due"), outside the arrears.
 */
final readonly class ArrearsReport
{
    /**
     * @param  list<ArrearsLine>  $lines  oldest due date first
     * @param  array<string, Money>  $buckets  AgingBucket value => owed
     * @param  array<string, int>  $counts  AgingBucket value => bills
     * @param  array<int, array<string, Money>>  $byBuilding  property id (0: none) => bucket => owed
     * @param  array<int, string>  $buildingNames  property id (0: none) => name
     */
    public function __construct(
        public CarbonImmutable $asOf,
        public array $lines,
        public array $buckets,
        public array $counts,
        public array $byBuilding,
        public Money $notYetDue,
        public int $notYetDueCount,
        public array $buildingNames = [],
    ) {}

    public function total(): Money
    {
        return Money::sum($this->buckets);
    }

    public function owedIn(AgingBucket $bucket): Money
    {
        return $this->buckets[$bucket->value];
    }

    /** What the building's tenants owe past due, all buckets. */
    public function owedBy(?int $propertyId): Money
    {
        return Money::sum($this->byBuilding[$propertyId ?? 0] ?? []);
    }

    /**
     * The buildings whose tenants owe past due, by name, each with what is
     * owed per bucket (the organization's bills of no building last).
     *
     * @return list<array{name: string, buckets: array<string, Money>, total: Money}>
     */
    public function buildingRows(): array
    {
        $rows = [];

        foreach ($this->byBuilding as $id => $buckets) {
            $full = [];

            foreach (AgingBucket::cases() as $bucket) {
                $full[$bucket->value] = $buckets[$bucket->value] ?? Money::zero();
            }

            $rows[] = ['id' => $id, 'name' => $this->buildingNames[$id] ?? '', 'buckets' => $full, 'total' => Money::sum($full)];
        }

        usort($rows, static fn (array $a, array $b): int => [$a['id'] === 0, mb_strtolower($a['name'])] <=> [$b['id'] === 0, mb_strtolower($b['name'])]);

        return array_map(static fn (array $row): array => ['name' => $row['name'], 'buckets' => $row['buckets'], 'total' => $row['total']], $rows);
    }

    /** How many tenants owe something past due. */
    public function tenantCount(): int
    {
        return count(array_unique(array_map(static fn (ArrearsLine $line): int => $line->bill->tenantProfileId, $this->lines)));
    }
}
