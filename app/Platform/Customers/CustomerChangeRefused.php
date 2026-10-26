<?php

declare(strict_types=1);

namespace App\Platform\Customers;

use RuntimeException;

/**
 * A change to a customer that the rules do not allow (no permission, no
 * reason, wrong status). The message is shown to the staff member as is.
 */
final class CustomerChangeRefused extends RuntimeException {}
