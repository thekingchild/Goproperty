<?php

declare(strict_types=1);

namespace Modules\Import\Contracts;

/** Errors block the commit; warnings are shown and accepted (DATA_IMPORT.md § 2.3). */
enum IssueSeverity: string
{
    case Error = 'error';
    case Warning = 'warning';
}
