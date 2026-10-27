<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Transfers;

use RuntimeException;

/**
 * A billing-transfer step that cannot happen (an invalid transition, an
 * unknown or unsuitable organization, a changed amount); nothing was
 * changed. The message is for the organization's owner.
 */
final class BillingTransferRefused extends RuntimeException {}
