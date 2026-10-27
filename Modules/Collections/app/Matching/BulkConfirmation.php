<?php

declare(strict_types=1);

namespace Modules\Collections\Matching;

/**
 * What a bulk confirmation of high-confidence matches did
 * (LineMatches::confirmHighConfidence): payments confirmed now, and those
 * refused (a bill voided meanwhile, …; their messages), each left waiting
 * in the confirmation queue.
 */
final readonly class BulkConfirmation
{
    /**
     * @param  list<string>  $problems
     */
    public function __construct(
        public int $confirmed,
        public int $refused,
        public array $problems = [],
    ) {}
}
