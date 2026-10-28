{{--
    Add or open an owner (Modules\Properties\Livewire\OwnerProfileForm).
    The ID number on file is shown masked only; typing a new one replaces it.
--}}
<x-page :heading="$owner ? $owner->display_name : __('Add owner')" :description="$owner ? __('Owner of buildings managed by :organization.', ['organization' => $organization->name]) : __('A person or a company that owns buildings you manage. Owners do not need an account.')">
    <x-slot:actions>
        <flux:button icon="arrow-left" :href="route('workspace.owners.index', $organization)" wire:navigate>{{ __('All owners') }}</flux:button>
        {{-- The owner's monthly statements (Modules\OwnerStatements, P08-05): owners and administrators. --}}
        @if ($owner && Route::has('workspace.owner-statements.index'))
            @can('owner_statements.view')
                <flux:button icon="document-text" :href="route('workspace.owner-statements.index', [$organization, $owner->id])" wire:navigate data-test="owner-statements-link">{{ __('Statements') }}</flux:button>
            @endcan
        @endif
    </x-slot:actions>

    @if ($flash)
        <flux:callout variant="success" icon="check-circle" :heading="$flash" role="status" data-test="status" />
    @elseif ($status)
        <flux:callout variant="success" icon="check-circle" :heading="$status" role="status" data-test="status" />
    @endif

    <form wire:submit="save" class="flex flex-col gap-6" novalidate>
        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="lg" level="2">{{ __('Who owns the building') }}</flux:heading>

            <div class="mt-4 grid gap-5 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <flux:radio.group wire:model.live="type" :label="__('Owner type')" variant="segmented" class="sm:max-w-xs" :disabled="! $canEdit">
                        @foreach ($types as $ownerType)
                            <flux:radio :value="$ownerType->value" :label="__($ownerType->label())" />
                        @endforeach
                    </flux:radio.group>
                </div>

                @if ($type === \Modules\Properties\Enums\OwnerType::Company->value)
                    <div class="sm:col-span-2">
                        <flux:input wire:model="companyName" :label="__('Company name')" maxlength="160" autocomplete="organization" :disabled="! $canEdit" required />
                    </div>
                @else
                    <flux:input wire:model="firstName" :label="__('First name')" maxlength="80" autocomplete="off" :disabled="! $canEdit" required />
                    <flux:input wire:model="lastName" :label="__('Last name')" maxlength="80" autocomplete="off" :disabled="! $canEdit" required />
                @endif
            </div>
        </section>

        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="lg" level="2">{{ __('Contact') }}</flux:heading>

            <div class="mt-4 grid gap-5 sm:grid-cols-2">
                <flux:input wire:model="phone" type="tel" :label="__('Phone number')" placeholder="0803 123 4567" maxlength="20" autocomplete="off" :disabled="! $canEdit" />
                <flux:input wire:model="email" type="email" :label="__('Email address')" maxlength="255" autocomplete="off" :disabled="! $canEdit" />

                <div class="sm:col-span-2">
                    <flux:textarea wire:model="address" :label="__('Address')" rows="2" maxlength="300" :disabled="! $canEdit" />
                </div>

                <flux:select wire:model="state" :label="__('State')" :disabled="! $canEdit">
                    <option value="">{{ __('Choose a state') }}</option>
                    @foreach ($states as $stateName)
                        <option value="{{ $stateName }}">{{ $stateName }}</option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="lga" :label="__('LGA')" maxlength="64" :disabled="! $canEdit" />
            </div>
        </section>

        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="lg" level="2">{{ __('Identity') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Optional. Stored encrypted, and only the last four digits are ever shown.') }}</flux:text>

            <div class="mt-4 grid gap-5 sm:grid-cols-2">
                <flux:select wire:model.live="idType" :label="__('ID type')" :disabled="! $canEdit">
                    @foreach ($idTypes as $option)
                        <option value="{{ $option->value }}">{{ __($option->label()) }}</option>
                    @endforeach
                </flux:select>

                @if ($idType !== \Modules\Properties\Enums\OwnerIdType::None->value)
                    <div>
                        @php($onFile = $maskedId !== null && $storedIdType?->value === $idType)
                        <flux:input wire:model="idValue"
                            :label="$idType === \Modules\Properties\Enums\OwnerIdType::Cac->value ? __('CAC number') : __('NIN')"
                            :placeholder="$onFile ? __('Leave empty to keep :masked', ['masked' => $maskedId]) : ($idType === \Modules\Properties\Enums\OwnerIdType::Cac->value ? 'RC 1234567' : '11 digits')"
                            maxlength="20" autocomplete="off" spellcheck="false" :disabled="! $canEdit" data-test="id-value" />
                        @if ($onFile)
                            <flux:text class="mt-1 text-sm" data-test="masked-id">{{ __('On file: :masked', ['masked' => $maskedId]) }}</flux:text>
                        @endif
                    </div>
                @elseif ($maskedId !== null && $canEdit)
                    <flux:text class="self-end text-sm">{{ __('Saving with "None" removes the number on file (:masked).', ['masked' => $maskedId]) }}</flux:text>
                @endif
            </div>
        </section>

        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:textarea wire:model="notes" :label="__('Notes')" :description="__('Only your team sees these.')" rows="3" maxlength="2000" :disabled="! $canEdit" />
        </section>

        @if ($buildings !== null)
            <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="owner-buildings">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <flux:heading size="lg" level="2">{{ __('Buildings') }}</flux:heading>
                    @if ($buildingCount > 0)
                        <flux:link :href="route('workspace.buildings.index', [$organization, 'owner' => $owner->id])" wire:navigate class="text-sm">{{ __('See in the buildings list') }}</flux:link>
                    @endif
                </div>
                @if ($buildings->isEmpty())
                    <flux:text class="mt-2">{{ __('This owner has no buildings yet. Choose them as the owner when you add or edit a building.') }}</flux:text>
                @else
                    <ul class="mt-3 divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($buildings as $building)
                            <li class="flex items-center justify-between gap-3 py-2" wire:key="owner-building-{{ $building->id }}">
                                <a href="{{ route('workspace.buildings.show', [$organization, $building]) }}" wire:navigate class="truncate font-medium text-zinc-900 hover:underline dark:text-white">{{ $building->name }}</a>
                                @if ($building->isArchived())
                                    <flux:badge size="sm" color="zinc">{{ __('Archived') }}</flux:badge>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    @if ($buildingCount > $buildings->count())
                        <flux:text class="mt-2 text-sm">{{ __('And :count more.', ['count' => $buildingCount - $buildings->count()]) }}</flux:text>
                    @endif
                @endif
            </section>
        @endif

        @if ($canEdit)
            <div class="flex gap-2">
                <flux:button type="submit" variant="primary" data-test="save-owner">{{ $owner ? __('Save owner') : __('Add owner') }}</flux:button>
                <flux:button :href="route('workspace.owners.index', $organization)" wire:navigate>{{ __('Cancel') }}</flux:button>
            </div>
        @else
            <flux:text>{{ __('You can see these details but not change them.') }}</flux:text>
        @endif
    </form>

    @if ($owner)
        {{-- The owner portal (P03-15): invite, resend, revoke, remove access. --}}
        <livewire:properties::owner-portal-access :owner="$owner->id" :key="'owner-portal-access-'.$owner->id" />
    @endif
</x-page>
