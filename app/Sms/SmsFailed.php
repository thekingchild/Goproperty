<?php

declare(strict_types=1);

namespace App\Sms;

use RuntimeException;

/** The provider did not take the message; the job retries with backoff. The message never holds the token. */
final class SmsFailed extends RuntimeException {}
