<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Contracts;

use RuntimeException;

/** An invoice email cannot be sent again now; the message says why, for the staff member. */
final class InvoiceEmailRefused extends RuntimeException {}
