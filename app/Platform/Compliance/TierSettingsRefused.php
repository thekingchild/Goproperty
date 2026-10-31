<?php

declare(strict_types=1);

namespace App\Platform\Compliance;

use RuntimeException;

/** A change to the Transaction Tier settings staff may not make; the message is shown to them. */
final class TierSettingsRefused extends RuntimeException {}
