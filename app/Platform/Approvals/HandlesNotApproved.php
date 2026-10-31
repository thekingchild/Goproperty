<?php

declare(strict_types=1);

namespace App\Platform\Approvals;

use App\Models\ApprovalRequest;

/**
 * An ApprovalHandler whose action holds something back while it waits
 * (e.g. a refund's amount, P06-07b) and must let it go when the request is
 * rejected or expires. Called inside the transaction that records the
 * decision or the expiry.
 */
interface HandlesNotApproved
{
    public function notApproved(ApprovalRequest $request): void;
}
