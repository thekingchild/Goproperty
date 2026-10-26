<?php

declare(strict_types=1);

namespace App\Installer;

enum DatabaseMarker
{
    /** No `installation_state` table or row yet. */
    case Absent;

    case InProgress;

    case Finalized;

    /** The configured database could not be read. */
    case Unreachable;
}
