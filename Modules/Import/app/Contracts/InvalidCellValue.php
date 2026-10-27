<?php

declare(strict_types=1);

namespace Modules\Import\Contracts;

use InvalidArgumentException;

/** A cell that cannot be read as what its column needs; the message is for the agency. */
final class InvalidCellValue extends InvalidArgumentException {}
