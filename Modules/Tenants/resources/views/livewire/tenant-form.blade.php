{{--
    Add or edit a tenant (Modules\Tenants\Livewire\TenantForm).
    The ID number on file is shown masked only; typing a new one replaces it.
--}}
<x-page :heading="$tenant ? __('Edit :name', ['name' => $tenant->fullName()]) : __('Add tenant')" :description="$tenant ? __('Changes are saved to the tenant\'s record and kept in the audit log.') : __('Someone who rents from :organization. Adding a tenant contacts nobody; you invite them to the portal when you are ready.', ['organization' => $organization->name])">
    <x-slot:actions>
        @if ($tenant)
            <flux:button icon="arrow-left" :href="route('workspace.tenants.show', [$organization, $tenant])" wire:navigate>{{ __('Back to tenant') }}</flux:button>
        @else
            <flux:button icon="arrow-left" :href="route('workspace.tenants.index', $organization)" wire:navigate>{{ __('All tenants') }}</flux:button>
        @endif
    </x-slot:actions>

    @if ($archivedMatch)
        <flux:callout variant="warning" icon="archive-box" role="alert" data-test="archived-match">
            <flux:callout.heading>{{ __(':name has this phone number and was archived', ['name' => $archivedMatch->fullName()]) }}</flux:callout.heading>
            <flux:callout.text>{{ __('Each tenant needs their own number. Restore :name instead of adding them again; their details on file come back, and you can edit them afterwards.', ['name' => $archivedMatch->fullName()]) }}</flux:callout.text>
            @if ($canRestoreMatch)
                <x-slot name="actions">
                    <flux:button size="sm" variant="primary" wire:click="restoreArchivedMatch" data-test="restore-instead">{{ __('Restore :name', ['name' => $archivedMatch->fullName()]) }}</flux:button>
                </x-slot>
            @endif
        </flux:callout>
    @endif

    <form wire:submit="save" class="flex flex-col gap-6" novalidate>
        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="lg" level="2">{{ __('Tenant') }}</flux:heading>

            <div class="mt-4 grid gap-5 sm:grid-cols-2">
                <flux:input wire:model="firstName" :label="__('First name')" maxlength="80" autocomplete="off" required />
                <flux:input wire:model="lastName" :label="__('Last name')" maxlength="80" autocomplete="off" required />
                <flux:input wire:model="phone" type="tel" :label="__('Phone number')" :description="__('How GoProperty recognises the tenant. Each tenant needs their own number.')" placeholder="0803 123 4567" maxlength="30" autocomplete="off" required data-test="tenant-phone" />
                <flux:input wire:model="email" type="email" :label="__('Email address')" maxlength="255" autocomplete="off" />

                <div class="sm:col-span-2">
                    <flux:textarea wire:model="address" :label="__('Address')" rows="2" maxlength="300" />
                </div>

                <flux:input wire:model="occupation" :label="__('Occupation')" maxlength="120" />
            </div>
        </section>

        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="lg" level="2">{{ __('Next of kin') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Optional. Who to call in an emergency.') }}</flux:text>

            <div class="mt-4 grid gap-5 sm:grid-cols-3">
                <flux:input wire:model="kinName" :label="__('Name')" maxlength="120" autocomplete="off" />
                <flux:input wire:model="kinPhone" type="tel" :label="__('Phone number')" placeholder="0805 987 6543" maxlength="30" autocomplete="off" />
                <flux:input wire:model="kinRelationship" :label="__('Relationship')" :placeholder="__('Brother, employer…')" maxlength="60" />
            </div>
        </section>

        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="lg" level="2">{{ __('Identity and KYC') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Optional. The ID number is stored encrypted, and only its last four characters are ever shown.') }}</flux:text>

            <div class="mt-4 grid gap-5 sm:grid-cols-2">
                <flux:select wire:model.live="idType" :label="__('ID type')">
                    @foreach ($idTypes as $option)
                        <option value="{{ $option->value }}">{{ __($option->label()) }}</option>
                    @endforeach
                </flux:select>

                @if ($idType !== \Modules\Tenants\Enums\TenantIdType::None->value)
                    <div>
                        @php($onFile = $maskedId !== null && $storedIdType?->value === $idType)
                        <flux:input wire:model="idValue" :label="__('ID number')"
                            :placeholder="$onFile ? __('Leave empty to keep :masked', ['masked' => $maskedId]) : ''"
                            :description="\Modules\Tenants\Enums\TenantIdType::tryFrom($idType)?->formatHint()"
                            maxlength="30" autocomplete="off" spellcheck="false" data-test="id-value" />
                        @if ($onFile)
                            <flux:text class="mt-1 text-sm" data-test="masked-id">{{ __('On file: :masked', ['masked' => $maskedId]) }}</flux:text>
                        @endif
                    </div>
                @elseif ($maskedId !== null)
                    <flux:text class="self-end text-sm">{{ __('Saving with "None" removes the number on file (:masked).', ['masked' => $maskedId]) }}</flux:text>
                @endif

                <flux:select wire:model="kycStatus" :label="__('KYC status')" :description="__('Being checked and Verified need an ID on file. Changing the ID of a checked tenant makes them Not verified again.')" data-test="kyc-status">
                    @foreach ($statuses as $option)
                        <option value="{{ $option->value }}">{{ __($option->label()) }}</option>
                    @endforeach
                </flux:select>
            </div>
        </section>

        <div class="flex gap-2">
            <flux:button type="submit" variant="primary" data-test="save-tenant">{{ $tenant ? __('Save tenant') : __('Add tenant') }}</flux:button>
            <flux:button :href="$tenant ? route('workspace.tenants.show', [$organization, $tenant]) : route('workspace.tenants.index', $organization)" wire:navigate>{{ __('Cancel') }}</flux:button>
        </div>
    </form>
</x-page>
