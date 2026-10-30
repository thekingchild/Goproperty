<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Contracts;

use RuntimeException;

/** A checkout that cannot start (no such bundle, no email for Paystack, Paystack down). The message is for the member. */
final class CheckoutRefused extends RuntimeException {}
