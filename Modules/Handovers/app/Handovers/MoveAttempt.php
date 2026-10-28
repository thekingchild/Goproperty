<?php

declare(strict_types=1);

namespace Modules\Handovers\Handovers;

use App\Tenancy\Moves\BuildingMove;

/**
 * The building move a transaction made, if it got that far: when the
 * transaction fails afterwards, the files the move copied are removed
 * (BuildingMove::discard()); the database rolls back by itself.
 */
final class MoveAttempt
{
    public ?BuildingMove $move = null;

    public function discard(): void
    {
        $this->move?->discard();
    }
}
