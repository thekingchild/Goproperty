<?php

declare(strict_types=1);

namespace App\Installer;

use RuntimeException;

/** A step that cannot continue; the message is safe to show the owner. */
final class InstallationException extends RuntimeException {}
