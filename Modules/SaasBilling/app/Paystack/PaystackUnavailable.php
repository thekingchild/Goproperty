<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Paystack;

use RuntimeException;

/** GoProperty's Paystack account could not be asked, or answered with an error or something unreadable: nothing may be concluded (try again later). */
final class PaystackUnavailable extends RuntimeException {}
