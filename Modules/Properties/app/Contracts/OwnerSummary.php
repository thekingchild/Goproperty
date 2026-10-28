<?php

declare(strict_types=1);

namespace Modules\Properties\Contracts;

/**
 * A property owner as other modules see it (OwnerProfileDirectory): plain
 * values, no model and never the ID number or contact details.
 * `archived` is true for an archived (soft-deleted) owner; `portalUserId`
 * is the user who follows the owner's buildings in the owner portal
 * (P03-15), null without portal access.
 */
final readonly class OwnerSummary
{
    public function __construct(
        public int $id,
        public int $organizationId,
        public string $name,
        public bool $archived,
        public ?int $portalUserId,
    ) {}
}
