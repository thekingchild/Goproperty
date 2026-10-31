<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Contracts;

use RuntimeException;

/** A refund cannot be asked for, approved or run now; the message says why, for the staff member. Nothing was refunded. */
final class RefundRefused extends RuntimeException {}
