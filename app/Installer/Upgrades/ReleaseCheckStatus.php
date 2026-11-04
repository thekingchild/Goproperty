<?php

declare(strict_types=1);

namespace App\Installer\Upgrades;

enum ReleaseCheckStatus: string
{
    case Found = 'found';

    /** GitHub answered: the repository has no published release yet. */
    case NoRelease = 'no_release';

    /** GitHub could not be reached or gave an answer that is not a release. */
    case Failed = 'failed';

    /** Not asked: outside production and staging, or no repository set. */
    case NotChecked = 'not_checked';
}
