<?php

declare(strict_types=1);

namespace Modules\Leasing\Import;

use Modules\Import\Contracts\ImportAction;
use Modules\Leasing\Models\Lease;

/** What LeaseService::importOngoing() did with a Leases row. */
final readonly class ImportedLeaseResult
{
    public function __construct(
        public Lease $lease,
        public ImportAction $action,
    ) {}
}
