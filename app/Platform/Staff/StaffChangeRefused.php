<?php

declare(strict_types=1);

namespace App\Platform\Staff;

use RuntimeException;

/**
 * A staff, role or approval change the rules do not allow. The message is
 * shown to the staff member as is.
 */
final class StaffChangeRefused extends RuntimeException {}
