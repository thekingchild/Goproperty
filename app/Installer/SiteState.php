<?php

declare(strict_types=1);

namespace App\Installer;

enum SiteState
{
    /** Lock file and database row both say finalized. */
    case Open;

    /** Shows "being set up" (INSTALLER.md § 3). */
    case NotInstalled;

    /** The lock file exists but the database could not be read. */
    case Unavailable;
}
