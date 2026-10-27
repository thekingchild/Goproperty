<?php

declare(strict_types=1);

namespace App\Members;

use RuntimeException;

/** The invited address already has an account: the person signs in, then accepts. */
final class InvitationNeedsSignIn extends RuntimeException {}
