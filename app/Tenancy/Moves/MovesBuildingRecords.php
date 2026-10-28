<?php

declare(strict_types=1);

namespace App\Tenancy\Moves;

/**
 * "Move these buildings' live records to organization X" (ARCHITECTURE.md
 * § 6.1, BILLING_PAYMENTS.md § 7.4): implemented by every module that owns
 * building-linked live records (Properties, Tenants, Leasing, Billing) and
 * by Core's media library (App\Media\MediaMover), and registered into
 * BuildingRecordMovers from the owner's service provider, the way import
 * targets are (§ 6.12).
 *
 * The management handover (Modules\Handovers) runs them through
 * OrganizationMoves::run(), in order(), inside its one transaction: any
 * failure rolls back the whole move. A mover moves only its own module's
 * records, only live ones (history stays with the organization that wrote
 * it), and changes `organization_id` only through BuildingMove::reassign(),
 * the one sanctioned path (BelongsToOrganization refuses it anywhere else).
 */
interface MovesBuildingRecords
{
    /** Lower runs first: Properties 10, Tenants 20, Leasing 30, Tenants' portal links 35, Billing 40, media 90. */
    public function order(): int;

    /**
     * Moves this module's live records of $move->propertyIds from
     * $move->from to $move->to, inside the move's transaction. Throws to
     * stop the whole move.
     */
    public function move(BuildingMove $move): void;
}
