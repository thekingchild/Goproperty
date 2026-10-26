<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/** A setup checklist item (`onboarding_checklist_items.status`). */
enum ChecklistStatus: string
{
    use HasValues;

    case Pending = 'pending';
    case Done = 'done';
}
