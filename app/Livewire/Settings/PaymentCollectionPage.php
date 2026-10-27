<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Banking\AccountNameMismatch;
use App\Banking\BankAccountRefused;
use App\Banking\BankAccounts;
use App\Banking\BankCatalogue;
use App\Banking\NameCheckOutcome;
use App\Models\OrganizationBankAccount;
use App\Models\User;
use App\Tenancy\BlockedWhileImpersonating;
use App\Tenancy\OrganizationContext;
use App\Theme\ThemeContext;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Settings → Payment collection, /o/{organization}/settings/collections
 * (ONBOARDING_COMPLIANCE.md § 4): the bank accounts tenants pay into. Add
 * one (the name is checked with the bank first), edit, check the name
 * again, make primary, deactivate, reactivate, remove. Numbers are shown
 * masked and never sent back to the browser. The Paystack connection
 * (§ 4.2, P03-08) joins this page.
 *
 * Org-owners only: seeing needs `payment_settings.view`, changing
 * `organization.payment_settings` (OrganizationBankAccountPolicy). Both
 * are dropped while GoProperty staff impersonate the user, and the whole
 * component is marked #[BlockedWhileImpersonating], so support neither
 * sees nor changes it. Read-only while the organization is suspended.
 */
#[Layout('layouts::app')]
#[Title('Payment collection')]
#[BlockedWhileImpersonating]
final class PaymentCollectionPage extends Component
{
    #[Locked]
    public int $organizationId;

    public string $bankCode = '';

    public string $accountNumber = '';

    public string $accountName = '';

    /** The account being edited, or null when the form adds one. */
    #[Locked]
    public ?int $editingId = null;

    /** The bank's name for the number just refused as a mismatch, offered to use instead. */
    #[Locked]
    public ?string $bankAccountName = null;

    #[Locked]
    public ?string $status = null;

    public function boot(OrganizationContext $context): void
    {
        abort_unless($context->has(), 404);
    }

    public function hydrate(OrganizationContext $context): void
    {
        abort_unless($context->id() === $this->organizationId, 404);
    }

    public function mount(OrganizationContext $context): void
    {
        $this->organizationId = $context->id();
        $this->authorize('viewAny', OrganizationBankAccount::class);
    }

    public function save(BankAccounts $accounts, BankCatalogue $banks): void
    {
        $editing = $this->editingId !== null ? $this->account($this->editingId) : null;
        $editing !== null ? $this->authorize('update', $editing) : $this->authorize('create', OrganizationBankAccount::class);

        $this->validate([
            'bankCode' => ['required', Rule::in($banks->codes())],
            // Editing: an empty number keeps the current one (never sent to the browser).
            'accountNumber' => [$editing === null ? 'required' : 'nullable', 'string', 'regex:/^\s*\d{3}[\s-]?\d{3}[\s-]?\d{4}\s*$/'],
            'accountName' => ['required', 'string', 'min:2', 'max:150', 'not_regex:/[<>]/'],
        ], [
            'bankCode.in' => 'Choose a bank from the list.',
            'accountNumber.regex' => 'Enter the 10-digit account number (NUBAN).',
            'accountName.not_regex' => 'Use plain text only.',
        ], ['bankCode' => 'bank', 'accountNumber' => 'account number', 'accountName' => 'account name']);

        try {
            $account = $editing !== null
                ? $accounts->update($this->user(), $editing, $this->bankCode, trim($this->accountNumber) !== '' ? $this->accountNumber : $editing->account_number, $this->accountName)
                : $accounts->add($this->user(), $this->bankCode, $this->accountNumber, $this->accountName);
        } catch (AccountNameMismatch $mismatch) {
            $this->bankAccountName = $mismatch->bankAccountName;
            $this->addError('accountName', $mismatch->getMessage());

            return;
        } catch (BankAccountRefused $refused) {
            $this->addError($refused->field, $refused->getMessage());

            return;
        }

        $this->resetForm();
        $this->status = match ($account->name_check_status->value) {
            'matched' => __(':bank :account saved. The bank confirmed the name “:name”.', ['bank' => $account->bank_name, 'account' => $account->masked(), 'name' => $account->account_name]),
            default => __(':bank :account saved. The name could not be checked with the bank now; check it again later.', ['bank' => $account->bank_name, 'account' => $account->masked()]),
        };
    }

    /** Takes the name the bank gave after a mismatch, and saves again. */
    public function useBankName(BankAccounts $accounts, BankCatalogue $banks): void
    {
        if ($this->bankAccountName === null) {
            return;
        }

        $this->accountName = $this->bankAccountName;
        $this->bankAccountName = null;
        $this->save($accounts, $banks);
    }

    public function edit(int $accountId): void
    {
        $account = $this->account($accountId);
        $this->authorize('update', $account);

        $this->resetForm();
        $this->editingId = $account->id;
        $this->bankCode = $account->bank_code;
        $this->accountName = $account->account_name;
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    public function checkName(int $accountId, BankAccounts $accounts): void
    {
        $account = $this->account($accountId);
        $this->authorize('update', $account);

        try {
            $result = $accounts->checkName($this->user(), $account);
        } catch (BankAccountRefused $refused) {
            $this->addError('list', $refused->getMessage());

            return;
        }

        $this->status = match ($result->outcome) {
            NameCheckOutcome::Matched => __('The bank confirmed the name “:name”.', ['name' => $result->bankAccountName]),
            NameCheckOutcome::Mismatched => __('The bank says this account is in the name of “:name”. Tenants will not be shown it until you correct it.', ['name' => $result->bankAccountName]),
            NameCheckOutcome::NotFound => __('The bank has no account with this number. Tenants will not be shown it until you correct it.'),
            NameCheckOutcome::Unavailable => __('The name could not be checked with the bank now. Try again later.'),
        };
    }

    public function makePrimary(int $accountId, BankAccounts $accounts): void
    {
        $account = $this->account($accountId);
        $this->authorize('update', $account);
        $this->attempt(fn () => $accounts->makePrimary($this->user(), $account), __(':bank :account is now the account on your invoices.', ['bank' => $account->bank_name, 'account' => $account->masked()]));
    }

    public function deactivate(int $accountId, BankAccounts $accounts): void
    {
        $account = $this->account($accountId);
        $this->authorize('update', $account);
        $this->attempt(fn () => $accounts->deactivate($this->user(), $account), __(':bank :account deactivated: tenants are no longer told to pay into it.', ['bank' => $account->bank_name, 'account' => $account->masked()]));
    }

    public function reactivate(int $accountId, BankAccounts $accounts): void
    {
        $account = $this->account($accountId);
        $this->authorize('update', $account);
        $this->attempt(fn () => $accounts->reactivate($this->user(), $account), __(':bank :account reactivated.', ['bank' => $account->bank_name, 'account' => $account->masked()]));
    }

    public function remove(int $accountId, BankAccounts $accounts): void
    {
        $account = $this->account($accountId);
        $this->authorize('delete', $account);
        $this->attempt(fn () => $accounts->remove($this->user(), $account), __(':bank :account removed.', ['bank' => $account->bank_name, 'account' => $account->masked()]));

        if ($this->editingId === $account->id) {
            $this->resetForm();
        }
    }

    public function render(BankAccounts $accounts, BankCatalogue $banks, OrganizationContext $context): View
    {
        return view('livewire.settings.payment-collection-page', [
            'accounts' => $accounts->all(),
            'primary' => $accounts->primary(),
            'banks' => $banks->all(),
            'canEdit' => $this->user()->can('create', OrganizationBankAccount::class),
            'editing' => $this->editingId !== null ? OrganizationBankAccount::query()->find($this->editingId) : null,
            'organization' => $context->get(),
            'brand' => app(ThemeContext::class)->current()->name(),
        ]);
    }

    /** One of this organization's accounts (the scope hides others: 404). */
    private function account(int $accountId): OrganizationBankAccount
    {
        $account = OrganizationBankAccount::query()->find($accountId);
        abort_if($account === null, 404);

        return $account;
    }

    private function attempt(callable $change, string $done): void
    {
        try {
            $change();
        } catch (BankAccountRefused $refused) {
            $this->addError('list', $refused->getMessage());
            $this->status = null;

            return;
        }

        $this->resetErrorBag('list');
        $this->status = $done;
    }

    private function resetForm(): void
    {
        $this->reset('bankCode', 'accountNumber', 'accountName', 'editingId', 'bankAccountName');
        $this->resetValidation();
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
