<?php

declare(strict_types=1);

namespace App\Banking;

use App\Enums\BankNameCheck;
use App\Enums\ChecklistItem;
use App\Models\Organization;
use App\Models\OrganizationBankAccount;
use App\Models\User;
use App\Onboarding\OnboardingChecklist;
use App\Tenancy\OrganizationContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The current organization's bank accounts, the ones tenants pay into
 * (ONBOARDING_COMPLIANCE.md § 4.1). The only writer of
 * OrganizationBankAccount: add, edit, check the name again, make primary,
 * deactivate, reactivate, remove (soft delete).
 *
 * The account name is checked with the bank before an account is saved
 * (BankAccountNameResolver): a different name or an unknown number is
 * refused with nothing saved; when no lookup can be made (no key, service
 * down) the account is saved as "name not checked" and can be checked
 * later. Lookups are limited per organization, since each one reveals an
 * account holder's name.
 *
 * Org-owners only (`organization.payment_settings`, OrganizationBankAccountPolicy,
 * checked here too): where tenants' money goes is the change a dishonest
 * insider or an impersonator would want, so administrators and support
 * staff never make it (ImpersonationGuard blocks the permission). Every
 * change is audited (BankAccountAudit), with the number masked.
 */
final readonly class BankAccounts
{
    public const int MAX_ACCOUNTS = 10;

    public const int LOOKUPS_PER_HOUR = 10;

    public function __construct(
        private OrganizationContext $context,
        private BankCatalogue $banks,
        private BankAccountNameResolver $resolver,
        private BankAccountAudit $audit,
        private OnboardingChecklist $checklist,
    ) {}

    /** HMAC of bank and number: finds the same account again without decrypting. */
    public static function fingerprint(string $bankCode, string $accountNumber): string
    {
        return hash_hmac('sha256', $bankCode.'|'.$accountNumber, (string) config('app.key'));
    }

    /** @return Collection<int, OrganizationBankAccount> active first, primary first */
    public function all(): Collection
    {
        return OrganizationBankAccount::query()->orderByDesc('active')->orderByDesc('is_default')->orderBy('id')->get();
    }

    /** The account invoices and the pay page show: the primary one if usable, else the oldest usable one. */
    public function primary(): ?OrganizationBankAccount
    {
        return OrganizationBankAccount::query()->usable()->orderByDesc('is_default')->orderBy('id')->first();
    }

    /** @throws BankAccountRefused */
    public function add(User $by, string $bankCode, string $accountNumber, string $accountName): OrganizationBankAccount
    {
        Gate::forUser($by)->authorize('create', OrganizationBankAccount::class);
        [$bankName, $number, $name] = $this->clean($bankCode, $accountNumber, $accountName);
        $fingerprint = self::fingerprint($bankCode, $number);
        $this->ensureNew($fingerprint);
        $this->ensureRoom();

        // The lookup is an HTTP call: made before the transaction, never holding a lock.
        $check = $this->lookup($bankCode, $number, $name);

        return DB::transaction(function () use ($by, $bankCode, $bankName, $number, $name, $fingerprint, $check): OrganizationBankAccount {
            $this->lockOrganization();
            $this->ensureNew($fingerprint);
            $this->ensureRoom();

            $account = new OrganizationBankAccount([
                'bank_code' => $bankCode,
                'bank_name' => $bankName,
                'account_number' => $number,
                'account_number_last4' => substr($number, -4),
                'account_fingerprint' => $fingerprint,
                'account_name' => $name,
                'created_by_user_id' => $by->id,
            ]);
            $this->applyCheck($account, $check);
            $account->is_default = $account->usable() && $this->primary() === null;
            $account->save();

            $this->audit->record('bank_account.added', $account, $by, 'Bank account added: '.$account->bank_name.' '.$account->masked());
            $this->completeChecklist($account, $by);

            return $account;
        });
    }

    /** @throws BankAccountRefused */
    public function update(User $by, OrganizationBankAccount $account, string $bankCode, string $accountNumber, string $accountName): OrganizationBankAccount
    {
        Gate::forUser($by)->authorize('update', $account);
        [$bankName, $number, $name] = $this->clean($bankCode, $accountNumber, $accountName);
        $fingerprint = self::fingerprint($bankCode, $number);

        if ($fingerprint === $account->account_fingerprint && $name === $account->account_name) {
            return $account;
        }

        $this->ensureNew($fingerprint, $account);
        $check = $this->lookup($bankCode, $number, $name);

        return DB::transaction(function () use ($by, $account, $bankCode, $bankName, $number, $name, $fingerprint, $check): OrganizationBankAccount {
            $this->lockOrganization();
            $this->ensureNew($fingerprint, $account);
            $account = OrganizationBankAccount::query()->lockForUpdate()->findOrFail($account->id);
            $before = ['bank' => $account->bank_name, 'account' => $account->masked(), 'name' => $account->account_name];

            $account->fill([
                'bank_code' => $bankCode,
                'bank_name' => $bankName,
                'account_number' => $number,
                'account_number_last4' => substr($number, -4),
                'account_fingerprint' => $fingerprint,
                'account_name' => $name,
            ]);
            $this->applyCheck($account, $check);
            $account->save();
            $this->settlePrimary($account);

            $this->audit->record('bank_account.updated', $account, $by, 'Bank account changed: '.$account->bank_name.' '.$account->masked(), ['before' => $before, 'account_name' => $account->account_name]);
            $this->completeChecklist($account, $by);

            return $account;
        });
    }

    /**
     * Asks the bank again, e.g. for an account saved while no lookup could be
     * made. The answer is stored whatever it is: an account the bank now
     * names differently stops being shown to tenants until it is corrected.
     *
     * @throws BankAccountRefused when the lookup limit is reached
     */
    public function checkName(User $by, OrganizationBankAccount $account): NameCheckResult
    {
        Gate::forUser($by)->authorize('update', $account);
        $this->throttle();
        $result = $this->resolver->check($account->bank_code, $account->account_number, $account->account_name);

        if ($result->outcome === NameCheckOutcome::Unavailable) {
            return $result;
        }

        DB::transaction(function () use ($by, $account, $result): void {
            $this->lockOrganization();
            $account = OrganizationBankAccount::query()->lockForUpdate()->findOrFail($account->id);
            $this->applyCheck($account, $result, keepName: $result->outcome !== NameCheckOutcome::Matched);
            $account->save();
            $this->settlePrimary($account);

            $this->audit->record('bank_account.name_checked', $account, $by, 'Bank account name checked: '.$account->name_check_status->label());
            $this->completeChecklist($account, $by);
        });

        return $result;
    }

    /** @throws BankAccountRefused */
    public function makePrimary(User $by, OrganizationBankAccount $account): void
    {
        Gate::forUser($by)->authorize('update', $account);

        if (! $account->usable()) {
            throw new BankAccountRefused('Only an active account whose name is not in doubt can be the primary account.', 'account');
        }

        DB::transaction(function () use ($by, $account): void {
            $this->lockOrganization();
            OrganizationBankAccount::query()->whereKeyNot($account->id)->where('is_default', true)->update(['is_default' => false]);
            $account->is_default = true;
            $account->save();

            $this->audit->record('bank_account.made_primary', $account, $by, 'Primary bank account: '.$account->bank_name.' '.$account->masked());
        });
    }

    public function deactivate(User $by, OrganizationBankAccount $account): void
    {
        Gate::forUser($by)->authorize('update', $account);

        DB::transaction(function () use ($by, $account): void {
            $this->lockOrganization();
            $account->active = false;
            $account->save();
            $this->settlePrimary($account);

            $this->audit->record('bank_account.deactivated', $account, $by, 'Bank account deactivated: '.$account->bank_name.' '.$account->masked());
        });
    }

    public function reactivate(User $by, OrganizationBankAccount $account): void
    {
        Gate::forUser($by)->authorize('update', $account);

        DB::transaction(function () use ($by, $account): void {
            $this->lockOrganization();
            $account->active = true;
            $account->save();
            $this->settlePrimary($account);

            $this->audit->record('bank_account.reactivated', $account, $by, 'Bank account reactivated: '.$account->bank_name.' '.$account->masked());
            $this->completeChecklist($account, $by);
        });
    }

    /** Soft delete: issued documents and statement imports may still point at it. */
    public function remove(User $by, OrganizationBankAccount $account): void
    {
        Gate::forUser($by)->authorize('delete', $account);

        DB::transaction(function () use ($by, $account): void {
            $this->lockOrganization();
            $account->active = false;
            $account->is_default = false;
            $account->save();
            $account->delete();
            $this->settlePrimary($account);

            $this->audit->record('bank_account.removed', $account, $by, 'Bank account removed: '.$account->bank_name.' '.$account->masked());
        });
    }

    /**
     * @return array{0: string, 1: string, 2: string} bank name, account number, account name
     *
     * @throws BankAccountRefused
     */
    private function clean(string $bankCode, string $accountNumber, string $accountName): array
    {
        $bankName = $this->banks->name($bankCode) ?? throw new BankAccountRefused('Choose a bank from the list.', 'bankCode');
        $number = (string) preg_replace('/[\s-]+/', '', $accountNumber);

        if (preg_match('/^\d{10}$/D', $number) !== 1) {
            throw new BankAccountRefused('Enter the 10-digit account number (NUBAN).');
        }

        $name = trim((string) preg_replace('/\s+/', ' ', $accountName));

        if (mb_strlen($name) < 2 || mb_strlen($name) > 150 || preg_match('/[<>]/', $name) === 1) {
            throw new BankAccountRefused('Enter the account name as the bank shows it.', 'accountName');
        }

        return [$bankName, $number, $name];
    }

    /** @throws BankAccountRefused */
    private function lookup(string $bankCode, string $number, string $name): NameCheckResult
    {
        $this->throttle();
        $result = $this->resolver->check($bankCode, $number, $name);

        return match ($result->outcome) {
            NameCheckOutcome::Mismatched => throw new AccountNameMismatch((string) $result->bankAccountName),
            NameCheckOutcome::NotFound => throw new BankAccountRefused('The bank has no account with this number. Check the number and the bank.'),
            default => $result,
        };
    }

    /** @throws BankAccountRefused */
    private function throttle(): void
    {
        $key = 'bank-name-check:'.$this->context->id();

        if (RateLimiter::tooManyAttempts($key, self::LOOKUPS_PER_HOUR)) {
            $minutes = max(1, (int) ceil(RateLimiter::availableIn($key) / 60));

            throw new BankAccountRefused("Too many account checks in the last hour. Try again in {$minutes} minutes.");
        }

        RateLimiter::hit($key, 3600);
    }

    private function applyCheck(OrganizationBankAccount $account, NameCheckResult $result, bool $keepName = false): void
    {
        [$status, $checkedAt] = match ($result->outcome) {
            NameCheckOutcome::Matched => [BankNameCheck::Matched, now()],
            NameCheckOutcome::Mismatched => [BankNameCheck::Mismatched, now()],
            NameCheckOutcome::NotFound => [BankNameCheck::NotFound, now()],
            NameCheckOutcome::Unavailable => [BankNameCheck::Unchecked, null],
        };

        $account->name_check_status = $status;
        $account->name_check_result = $result->bankAccountName;
        $account->name_checked_at = $checkedAt;

        // Tenants see the name exactly as their bank app will show it.
        if ($result->outcome === NameCheckOutcome::Matched && ! $keepName && $result->bankAccountName !== null) {
            $account->account_name = $result->bankAccountName;
        }
    }

    /**
     * Exactly one primary account among the usable ones while any exists: an
     * account that stops being usable hands it to the oldest usable one.
     */
    private function settlePrimary(OrganizationBankAccount $changed): void
    {
        if (! $changed->usable() && $changed->is_default) {
            $changed->is_default = false;
            $changed->save();
        }

        $usable = OrganizationBankAccount::query()->usable();

        if (! (clone $usable)->where('is_default', true)->exists()) {
            $next = $usable->orderBy('id')->first();

            if ($next !== null) {
                $next->is_default = true;
                $next->save();
            }
        }
    }

    /** @throws BankAccountRefused */
    private function ensureNew(string $fingerprint, ?OrganizationBankAccount $except = null): void
    {
        $query = OrganizationBankAccount::query()->where('account_fingerprint', $fingerprint);

        if ($except !== null) {
            $query->whereKeyNot($except->id);
        }

        if ($query->exists()) {
            throw new BankAccountRefused('This account has already been added.');
        }
    }

    /** @throws BankAccountRefused */
    private function ensureRoom(): void
    {
        if (OrganizationBankAccount::query()->count() >= self::MAX_ACCOUNTS) {
            throw new BankAccountRefused('You can add up to '.self::MAX_ACCOUNTS.' bank accounts. Remove one you no longer use first.', 'bankCode');
        }
    }

    private function completeChecklist(OrganizationBankAccount $account, User $by): void
    {
        if ($account->usable()) {
            $this->checklist->complete(ChecklistItem::Collections, $by, ['bank_account' => true]);
        }
    }

    /** Serialises bank account changes of one organization (primary, duplicates, the limit). */
    private function lockOrganization(): void
    {
        Organization::query()->whereKey($this->context->id())->lockForUpdate()->first();
    }
}
