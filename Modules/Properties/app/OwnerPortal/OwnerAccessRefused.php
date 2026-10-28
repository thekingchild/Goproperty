<?php

declare(strict_types=1);

namespace Modules\Properties\OwnerPortal;

use RuntimeException;

/** An owner-portal access action that cannot be done; the message is for the agency member. */
final class OwnerAccessRefused extends RuntimeException {}
