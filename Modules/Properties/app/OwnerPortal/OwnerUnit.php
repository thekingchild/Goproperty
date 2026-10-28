<?php

declare(strict_types=1);

namespace Modules\Properties\OwnerPortal;

/** A unit of the owner's building with its occupancy and active lease (P03-15). */
final readonly class OwnerUnit
{
    public function __construct(
        public int $id,
        public string $label,
        public string $typeLabel,
        public string $status,
        public string $statusLabel,
        public string $statusColor,
        public ?OwnerLease $lease,
    ) {}
}
