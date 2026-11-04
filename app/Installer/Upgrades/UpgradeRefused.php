<?php

declare(strict_types=1);

namespace App\Installer\Upgrades;

use RuntimeException;

/** The upgrade step will not run now; the message says why, in words for the page. */
final class UpgradeRefused extends RuntimeException {}
