<?php

declare(strict_types=1);

namespace App\Banking;

/** What an account-name lookup found (BankAccountNameResolver). */
enum NameCheckOutcome: string
{
    /** The bank's name for the account matches the name given. */
    case Matched = 'matched';

    /** The bank knows the account, under another name. */
    case Mismatched = 'mismatched';

    /** The bank knows no such account number. */
    case NotFound = 'not_found';

    /** No answer: no key configured, the service was down or refused us. */
    case Unavailable = 'unavailable';
}
