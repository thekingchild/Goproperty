<?php

declare(strict_types=1);

namespace App\Installer\Web;

enum GrantStatus: string
{
    case Valid = 'valid';
    /** No grant, or another session holds it. */
    case Missing = 'missing';
    /** 60 minutes passed without a completed step. */
    case Expired = 'expired';
}
