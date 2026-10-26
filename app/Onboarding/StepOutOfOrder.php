<?php

declare(strict_types=1);

namespace App\Onboarding;

use RuntimeException;

/** A wizard step was saved that is not the current one (e.g. from a second, stale tab). */
final class StepOutOfOrder extends RuntimeException {}
