<?php

declare(strict_types=1);

namespace App\Members;

use RuntimeException;

/** The invitation link is unknown, already used, revoked or expired. Every case answers the same way, so a link cannot be probed. */
final class InvitationUnavailable extends RuntimeException {}
