<?php

declare(strict_types=1);

namespace App\Sms;

use RuntimeException;
use Throwable;

/**
 * The provider did not take the message; the job retries with backoff. The
 * message never holds the token. `outcomeUnknown`: the provider could not
 * be reached or did not answer in time, so it may have taken the message
 * after all (its SMS credit is then never given back, P05-03); otherwise
 * it refused it outright.
 */
final class SmsFailed extends RuntimeException
{
    public function __construct(string $message = '', public readonly bool $outcomeUnknown = false, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function unreachable(string $message): self
    {
        return new self($message, outcomeUnknown: true);
    }
}
