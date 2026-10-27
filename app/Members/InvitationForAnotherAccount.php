<?php

declare(strict_types=1);

namespace App\Members;

use RuntimeException;

/** Someone signed in with an account other than the invited address tried to accept: refused, the invitation is bound to its address. */
final class InvitationForAnotherAccount extends RuntimeException {}
