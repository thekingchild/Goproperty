<?php

declare(strict_types=1);

namespace Modules\Tenants\Portal;

use RuntimeException;

/** A portal-access action that cannot be done; the message is for the agency member. */
final class PortalAccessRefused extends RuntimeException {}
