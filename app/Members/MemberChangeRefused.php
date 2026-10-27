<?php

declare(strict_types=1);

namespace App\Members;

use RuntimeException;

/**
 * A member or invitation change the rules do not allow (the last owner,
 * an address already invited or already a member, too many invitations).
 * The message is shown to the person who asked.
 */
final class MemberChangeRefused extends RuntimeException {}
