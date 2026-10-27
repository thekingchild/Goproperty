<?php

declare(strict_types=1);

namespace Modules\Import\Contracts;

/** What upsert() did: the action and the record's id (null when skipped, with the reason). */
final readonly class UpsertResult
{
    public function __construct(
        public ImportAction $action,
        public ?int $recordId = null,
        public ?string $reason = null,
    ) {}
}
