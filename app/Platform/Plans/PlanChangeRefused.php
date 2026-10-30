<?php

declare(strict_types=1);

namespace App\Platform\Plans;

use RuntimeException;

/** A plan edit that is not allowed, with words for the staff member. */
final class PlanChangeRefused extends RuntimeException {}
