<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * The result of checking a bank account's name with the bank
 * (ONBOARDING_COMPLIANCE.md § 4.1, App\Banking\BankAccounts): the name the
 * bank returned matches the name given, does not, the bank knows no such
 * account, or no lookup could be made yet (no key configured, the lookup
 * service was down). Only matched and unchecked accounts are shown to
 * tenants; the other two must be corrected first.
 */
enum BankNameCheck: string
{
    use HasValues;

    case Unchecked = 'unchecked';
    case Matched = 'matched';
    case Mismatched = 'mismatched';
    case NotFound = 'not_found';

    public function label(): string
    {
        return match ($this) {
            self::Unchecked => 'Name not checked',
            self::Matched => 'Name checked',
            self::Mismatched => 'Name does not match',
            self::NotFound => 'Account not found',
        };
    }

    /** Whether tenants may be told to pay into an account with this result. */
    public function usable(): bool
    {
        return $this === self::Matched || $this === self::Unchecked;
    }
}
