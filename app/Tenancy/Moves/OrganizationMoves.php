<?php

declare(strict_types=1);

namespace App\Tenancy\Moves;

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/**
 * Runs a building move (ARCHITECTURE.md § 6.1, BILLING_PAYMENTS.md § 7.7
 * rule 2): every registered MovesBuildingRecords in order, each starting
 * in the context of the organization the buildings leave, inside the
 * caller's transaction, so any failure rolls back the whole move. Only the
 * management handover's service calls it (a test holds Core and every
 * other module to that).
 *
 * Work outside the database (moving files) registers its undo on the move:
 * run() undoes it when a mover fails; the caller undoes it when its own
 * transaction fails afterwards (BuildingMove::discard()). Removing the old
 * copies waits for the commit.
 */
final readonly class OrganizationMoves
{
    public function __construct(
        private BuildingRecordMovers $movers,
        private OrganizationContext $context,
    ) {}

    /**
     * @param  list<int>  $propertyIds
     * @param  array<int, int|null>  $restoreOwnerProfileIds  on a return: building id => owner profile in $to before the handover
     *
     * @throws BuildingMoveFailed|Throwable whatever a mover threw; the caller's transaction must roll back
     */
    public function run(
        Organization $from,
        Organization $to,
        MoveDirection $direction,
        array $propertyIds,
        int $handoverId,
        Organization $landlordOrganization,
        ?User $landlord,
        ?int $landlordOwnerProfileId = null,
        array $restoreOwnerProfileIds = [],
    ): BuildingMove {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A building move runs inside one database transaction.');
        }

        if ($from->is($to)) {
            throw new LogicException('Buildings move between two organizations.');
        }

        if ($propertyIds === []) {
            throw new BuildingMoveFailed('No buildings to move.');
        }

        $move = new BuildingMove($from, $to, $direction, array_values(array_unique($propertyIds)), $handoverId, $landlordOrganization, $landlord, $landlordOwnerProfileId, $restoreOwnerProfileIds, $this->context);
        $move->start();

        try {
            foreach ($this->movers->all() as $mover) {
                $this->context->run($from, static fn () => $mover->move($move));
            }
        } catch (Throwable $failure) {
            $move->stop();
            $move->discard();

            throw $failure;
        }

        $move->stop();

        foreach ($move->afterCommitCallbacks() as $callback) {
            DB::afterCommit($callback);
        }

        return $move;
    }
}
