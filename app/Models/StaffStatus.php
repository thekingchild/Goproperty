<?php

declare(strict_types=1);

namespace App\Models;

enum StaffStatus: string
{
    case Invited = 'invited';
    case Active = 'active';
    case Deactivated = 'deactivated';
}
