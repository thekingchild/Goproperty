<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Enums;

use App\Enums\Concerns\HasValues;

/**
 * A report's state (`group_reports.status`; P07-04): open until a
 * moderator hides or removes the item (actioned) or dismisses its reports.
 */
enum ReportStatus: string
{
    use HasValues;

    case Open = 'open';
    case Actioned = 'actioned';
    case Dismissed = 'dismissed';
}
