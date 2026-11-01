<?php

declare(strict_types=1);

namespace App\Backup\Operations;

use RuntimeException;

/** A backups-console request that cannot be made; the message says why, in words. */
final class BackupRefused extends RuntimeException {}
