<?php

declare(strict_types=1);

namespace App\Platform\Approvals;

use App\Enums\Concerns\HasValues;

enum ApprovalStatus: string
{
    use HasValues;

    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Expired = 'expired';
}
