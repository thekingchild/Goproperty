<?php

declare(strict_types=1);

namespace App\Platform\Approvals;

use RuntimeException;

/** An approval decision the two-person rules do not allow; the message is shown as is. */
final class ApprovalRefused extends RuntimeException {}
