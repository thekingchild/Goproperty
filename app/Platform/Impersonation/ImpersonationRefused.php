<?php

declare(strict_types=1);

namespace App\Platform\Impersonation;

use RuntimeException;

/** A support session that may not start, be handed off or be ended; the message says why. */
final class ImpersonationRefused extends RuntimeException {}
