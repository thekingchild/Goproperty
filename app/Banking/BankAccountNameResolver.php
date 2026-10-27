<?php

declare(strict_types=1);

namespace App\Banking;

use Illuminate\Container\Attributes\Bind;

/**
 * Asks the bank, through a lookup service, whose name an account number is
 * in, and compares it with the name given (ONBOARDING_COMPLIANCE.md § 4.1).
 * Never throws for a service problem: that is an Unavailable result, and
 * the account is saved as "name not checked".
 */
#[Bind(PaystackBankAccountNameResolver::class)]
interface BankAccountNameResolver
{
    public function check(string $bankCode, string $accountNumber, string $expectedName): NameCheckResult;
}
