<?php

declare(strict_types=1);

namespace App\Compliance;

use RuntimeException;

/** A Transaction Tier application change that is not allowed; the message is for the person who tried. */
class TierApplicationRefused extends RuntimeException {}
