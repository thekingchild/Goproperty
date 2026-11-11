<?php

declare(strict_types=1);

namespace App\Theme;

use RuntimeException;

/** A draft, publish or restore that would change a setting the staff member may not change, with words for them. */
final class ThemeChangeRefused extends RuntimeException {}
