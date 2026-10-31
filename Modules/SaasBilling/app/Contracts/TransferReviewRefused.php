<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Contracts;

use RuntimeException;

/** A bank transfer cannot be confirmed or rejected now; the message says why, for the staff member. */
final class TransferReviewRefused extends RuntimeException {}
