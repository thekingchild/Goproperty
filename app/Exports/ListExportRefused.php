<?php

declare(strict_types=1);

namespace App\Exports;

use RuntimeException;

/** An export that may not start now (too many exports, too many rows); the message is for the member. */
final class ListExportRefused extends RuntimeException {}
