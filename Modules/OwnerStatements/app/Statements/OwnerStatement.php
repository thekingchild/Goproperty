<?php

declare(strict_types=1);

namespace Modules\OwnerStatements\Statements;

use App\Support\Money;

/**
 * One owner's month with one agency (P08-05; BRANDING_DOCUMENTS.md § 4
 * "Owner statement"): the terms it was worked out with, each building's
 * part and the totals. Live (StatementBuilder) until the agency marks the
 * month settled; then the snapshot kept on the settlement (toArray() /
 * fromArray(), `owner_statement_settlements.figures`), which never changes.
 */
final readonly class OwnerStatement
{
    /** The snapshot's format, kept with it. */
    public const int FORMAT = 1;

    /** @param  list<BuildingStatement>  $buildings */
    public function __construct(
        public int $organizationId,
        public int $ownerProfileId,
        public string $ownerName,
        public StatementMonth $month,
        public ?int $commissionBps,
        public bool $utilitiesToOwner,
        public array $buildings,
        public StatementFigures $totals,
    ) {}

    public function commissionSet(): bool
    {
        return $this->commissionBps !== null;
    }

    /** "10%", "7.5%", or null while not set. */
    public function commissionRate(): ?string
    {
        return $this->commissionBps === null ? null : self::rate($this->commissionBps);
    }

    public static function rate(int $basisPoints): string
    {
        return rtrim(rtrim(number_format($basisPoints / 100, 2, '.', ''), '0'), '.').'%';
    }

    public function netDue(): Money
    {
        return $this->totals->netDue;
    }

    /** Whether anything happened in the month (money, bills or expenses). */
    public function isEmpty(): bool
    {
        foreach ($this->buildings as $building) {
            if ($building->collections !== [] || $building->bills !== [] || $building->expenses !== []) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'format' => self::FORMAT,
            'organization_id' => $this->organizationId,
            'owner_profile_id' => $this->ownerProfileId,
            'owner_name' => $this->ownerName,
            'month' => $this->month->key(),
            'commission_bps' => $this->commissionBps,
            'utilities_to_owner' => $this->utilitiesToOwner,
            'buildings' => array_map(static fn (BuildingStatement $building): array => $building->toArray(), $this->buildings),
            'totals' => $this->totals->toArray(),
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data, StatementMonth $month): self
    {
        $totals = $data['totals'] ?? [];
        $bps = $data['commission_bps'] ?? null;

        return new self(
            Snapshot::int($data, 'organization_id'),
            Snapshot::int($data, 'owner_profile_id'),
            Snapshot::text($data, 'owner_name'),
            $month,
            is_numeric($bps) ? (int) $bps : null,
            (bool) ($data['utilities_to_owner'] ?? false),
            array_map(BuildingStatement::fromArray(...), Snapshot::rows($data, 'buildings')),
            StatementFigures::fromArray(is_array($totals) ? $totals : []),
        );
    }
}
