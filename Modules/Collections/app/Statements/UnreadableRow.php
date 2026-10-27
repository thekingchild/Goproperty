<?php

declare(strict_types=1);

namespace Modules\Collections\Statements;

use RuntimeException;

/** A statement row that looks like a transaction but cannot be read; the message says why, for the report. */
final class UnreadableRow extends RuntimeException {}
