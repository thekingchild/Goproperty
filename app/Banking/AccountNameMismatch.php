<?php

declare(strict_types=1);

namespace App\Banking;

/**
 * The bank has the account under another name. Nothing was saved; the
 * person can correct the number or use the bank's name.
 */
final class AccountNameMismatch extends BankAccountRefused
{
    public function __construct(public readonly string $bankAccountName)
    {
        parent::__construct('The bank says this account is in the name of “'.$bankAccountName.'”. Check the account number, or use the bank\'s name.', 'accountName');
    }
}
