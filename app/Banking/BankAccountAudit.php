<?php

declare(strict_types=1);

namespace App\Banking;

use App\Models\OrganizationBankAccount;
use App\Models\User;

/**
 * Writes bank account changes to the hash-chained audit log
 * (PLATFORM_CONSOLE.md § 7), log name `payment_settings`, with the user as
 * causer. Entries name the bank and the masked number ("···6789"), never
 * the full account number.
 */
final class BankAccountAudit
{
    public const string LOG = 'payment_settings';

    /** @param  array<string, mixed>  $properties */
    public function record(string $event, OrganizationBankAccount $account, ?User $by, string $description, array $properties = []): void
    {
        $log = activity(self::LOG)
            ->event($event)
            ->performedOn($account)
            ->withProperties([
                'bank' => $account->bank_name,
                'account' => $account->masked(),
                'name_check' => $account->name_check_status->value,
                ...$properties,
            ]);

        ($by !== null ? $log->causedBy($by) : $log->causedByAnonymous())->log($description);
    }
}
