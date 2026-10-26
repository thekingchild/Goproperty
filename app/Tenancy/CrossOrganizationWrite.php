<?php

declare(strict_types=1);

namespace App\Tenancy;

use LogicException;

/** A record was created in, or moved to, another organization than the current one. */
final class CrossOrganizationWrite extends LogicException {}
