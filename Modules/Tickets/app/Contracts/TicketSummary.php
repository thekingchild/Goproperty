<?php

declare(strict_types=1);

namespace Modules\Tickets\Contracts;

use Carbon\CarbonImmutable;

/**
 * A ticket as other modules see it (Contracts\TicketService): plain values,
 * no model. `status`, `category` and `priority` are the `tickets` column
 * values, each with its label.
 */
final readonly class TicketSummary
{
    public function __construct(
        public int $id,
        public int $organizationId,
        public string $number,
        public string $title,
        public string $status,
        public string $statusLabel,
        public bool $open,
        public string $category,
        public string $categoryLabel,
        public string $priority,
        public string $priorityLabel,
        public int $propertyId,
        public int $unitId,
        public ?int $leaseId,
        public ?int $tenantProfileId,
        public ?int $assignedToUserId,
        public ?CarbonImmutable $submittedAt,
        public ?CarbonImmutable $resolvedAt,
        public ?CarbonImmutable $closedAt,
    ) {}
}
