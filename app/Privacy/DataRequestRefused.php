<?php

declare(strict_types=1);

namespace App\Privacy;

use RuntimeException;

/** An export or erasure that may not be asked for or carried out, with the reason in words. */
final class DataRequestRefused extends RuntimeException {}
