<?php

declare(strict_types=1);

namespace App\Subscriptions;

use RuntimeException;

/** A subscription change that is not allowed, with words for the person who asked. */
final class SubscriptionChangeRefused extends RuntimeException {}
