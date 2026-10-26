<?php

declare(strict_types=1);

namespace App\Installer\Requirements;

enum CheckStatus: string
{
    case Pass = 'pass';
    case Fail = 'fail';

    /** Advisory problem, or something only the web request can check. */
    case Warn = 'warn';
}
