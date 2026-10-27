{{--
    Settings → Payment collection (App\Livewire\Settings\PaymentCollectionPage,
    ONBOARDING_COMPLIANCE.md § 4.1): bank accounts, masked, with the bank's
    name check. Org-owners only; never shown to impersonating staff.
--}}
@php
    use App\Enums\BankNameCheck;

    $checkColor = fn (BankNameCheck $check): string => match ($check) {
        BankNameCheck::Matched => 'green',
        BankNameCheck::Unchecked => 'amber',
        default => 'red',
    };
@endphp

<x-page :heading="__('Settings')" :description="__('Your organization\'s details, how tenants pay you, and how your documents look.')">
    <x-workspace.settings-nav current="workspace.settings.collections" />

    @if ($status)
        <flux:callout variant="success" icon="check-circle" :heading="$status" data-test="status" />
    @endif

    @error('list')
        <flux:callout variant="danger" icon="exclamation-triangle" :heading="$message" data-test="refusal" />
    @enderror

    <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="bank-accounts">
        <flux:heading size="lg" level="2">{{ __('Bank accounts') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Tenants pay straight into your own account, with the bill\'s reference in the narration. :brand never holds the money. The primary account is printed on your invoices.', ['brand' => $brand]) }}</flux:text>

        @if ($accounts->isEmpty())
            <flux:text class="mt-4" data-test="no-accounts">{{ __('No bank account yet. Add the account tenants should pay into.') }}</flux:text>
        @else
            <ul class="mt-4 divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($accounts as $account)
                    <li class="flex flex-col gap-3 py-4 sm:flex-row sm:items-center sm:justify-between" wire:key="account-{{ $account->id }}" data-test="account-{{ $account->id }}">
                        <div class="flex flex-col gap-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-medium text-zinc-900 dark:text-white">{{ $account->bank_name }} {{ $account->masked() }}</span>
                                @if ($account->is_default && $account->usable())
                                    <flux:badge size="sm" color="blue">{{ __('Primary') }}</flux:badge>
                                @endif
                                <flux:badge size="sm" :color="$checkColor($account->name_check_status)">{{ $account->name_check_status->label() }}</flux:badge>
                                @unless ($account->active)
                                    <flux:badge size="sm" color="zinc">{{ __('Inactive') }}</flux:badge>
                                @endunless
                            </div>
                            <flux:text>{{ $account->account_name }}</flux:text>
                            @if (in_array($account->name_check_status, [BankNameCheck::Mismatched, BankNameCheck::NotFound], true))
                                <flux:text class="text-red-600 dark:text-red-400">
                                    @if ($account->name_check_status === BankNameCheck::Mismatched)
                                        {{ __('The bank says this account is in the name of “:name”. Tenants are not shown it until you correct it.', ['name' => $account->name_check_result]) }}
                                    @else
                                        {{ __('The bank has no account with this number. Tenants are not shown it until you correct it.') }}
                                    @endif
                                </flux:text>
                            @endif
                        </div>

                        @if ($canEdit)
                            <div class="flex flex-wrap gap-2">
                                @if ($account->active && $account->name_check_status !== BankNameCheck::Matched)
                                    <flux:button size="sm" wire:click="checkName({{ $account->id }})">{{ __('Check name') }}</flux:button>
                                @endif
                                @if ($account->usable() && ! $account->is_default)
                                    <flux:button size="sm" wire:click="makePrimary({{ $account->id }})">{{ __('Make primary') }}</flux:button>
                                @endif
                                <flux:button size="sm" wire:click="edit({{ $account->id }})">{{ __('Edit') }}</flux:button>
                                @if ($account->active)
                                    <flux:button size="sm" wire:click="deactivate({{ $account->id }})" wire:confirm="{{ __('Stop showing this account to tenants?') }}">{{ __('Deactivate') }}</flux:button>
                                @else
                                    <flux:button size="sm" wire:click="reactivate({{ $account->id }})">{{ __('Reactivate') }}</flux:button>
                                    <flux:button size="sm" variant="danger" wire:click="remove({{ $account->id }})" wire:confirm="{{ __('Remove this account from the list?') }}">{{ __('Remove') }}</flux:button>
                                @endif
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    @if ($canEdit)
        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="account-form">
            <flux:heading size="lg" level="2">
                {{ $editing ? __('Edit :bank :account', ['bank' => $editing->bank_name, 'account' => $editing->masked()]) : __('Add a bank account') }}
            </flux:heading>
            <flux:text class="mt-1">{{ __('We check the account name with the bank before saving, so tenants never pay into a mistyped account.') }}</flux:text>

            <form wire:submit="save" class="mt-5 grid gap-5 sm:grid-cols-2">
                <flux:select wire:model="bankCode" :label="__('Bank')" required>
                    <option value="">{{ __('Choose a bank') }}</option>
                    @foreach ($banks as $code => $bankName)
                        <option value="{{ $code }}">{{ $bankName }}</option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="accountNumber" :label="__('Account number')" inputmode="numeric" maxlength="13" autocomplete="off"
                    :placeholder="$editing ? __(':account — leave empty to keep it', ['account' => $editing->masked()]) : __('10 digits')" :required="! $editing" />

                <div class="sm:col-span-2">
                    <flux:input wire:model="accountName" :label="__('Account name')" maxlength="150" :description="__('As the bank shows it, e.g. ADEYEMI REALTY LTD.')" required />
                    @if ($bankAccountName)
                        <div class="mt-2">
                            <flux:button size="sm" wire:click="useBankName" data-test="use-bank-name">{{ __('Use “:name”', ['name' => $bankAccountName]) }}</flux:button>
                        </div>
                    @endif
                </div>

                <div class="flex gap-2 sm:col-span-2">
                    <flux:button type="submit" variant="primary">{{ $editing ? __('Save changes') : __('Check and add account') }}</flux:button>
                    @if ($editing)
                        <flux:button wire:click="cancel">{{ __('Cancel') }}</flux:button>
                    @endif
                </div>
            </form>
        </section>
    @else
        <flux:text>{{ __('You can see these accounts but not change them.') }}</flux:text>
    @endif
</x-page>
