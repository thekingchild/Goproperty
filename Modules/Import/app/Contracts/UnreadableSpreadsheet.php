<?php

declare(strict_types=1);

namespace Modules\Import\Contracts;

use RuntimeException;

/** A file SpreadsheetReader could not read as a spreadsheet (damaged, missing, or not what its type said). */
final class UnreadableSpreadsheet extends RuntimeException {}
