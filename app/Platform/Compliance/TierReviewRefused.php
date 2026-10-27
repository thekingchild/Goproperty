<?php

declare(strict_types=1);

namespace App\Platform\Compliance;

use RuntimeException;

/** A review step staff may not take; the message is shown to them. */
final class TierReviewRefused extends RuntimeException {}
