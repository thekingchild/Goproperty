<?php

declare(strict_types=1);

namespace Modules\Properties\Contracts;

/**
 * How other modules read property owners (ARCHITECTURE.md § 8 rule 3; the
 * owner statements, P08-05): never the `owner_profiles` table itself.
 * Answers for the current organization only (OrganizationContext), so
 * another organization's owner is simply not found. No permission check:
 * the caller checks its own policy. Bound in PropertiesServiceProvider.
 */
interface OwnerProfileDirectory
{
    /** The owner (archived ones too), or null when the current organization has no such owner. */
    public function find(int $ownerProfileId): ?OwnerSummary;

    /**
     * The owner's buildings (archived ones too: their history stays), by
     * name; buildings removed by an import rollback are left out.
     *
     * @return list<PropertySummary>
     */
    public function buildingsOf(int $ownerProfileId): array;
}
