<?php

declare(strict_types=1);

namespace Modules\Handovers\Handovers;

use RuntimeException;

/** A handover step the rules do not allow (BILLING_PAYMENTS.md § 7); nothing changed. The message is for the person acting. */
final class HandoverRefused extends RuntimeException {}
