{{--
    A unit's details, shared by the unit form and the bulk form
    (`labelField`: whether the single-unit label field is shown).
--}}
<section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
    <flux:heading size="lg" level="2">{{ __('The unit') }}</flux:heading>

    <div class="mt-4 grid gap-5 sm:grid-cols-2">
        @if ($labelField)
            <flux:input wire:model="unitLabel" :label="__('Unit label')" :description="__('How tenants and your team know it, such as “Flat 3B” or “Shop 2”.')" placeholder="Flat 1" maxlength="60" autocomplete="off" required data-test="unit-label" />
        @endif

        <flux:select wire:model="unitType" :label="__('Unit type')" data-test="unit-type">
            @foreach ($types as $option)
                <option value="{{ $option->value }}">{{ __($option->label()) }}</option>
            @endforeach
        </flux:select>

        <flux:input wire:model="bedrooms" type="number" inputmode="numeric" min="0" max="20" :label="__('Bedrooms')" :badge="__('Optional')" />
        <flux:input wire:model="bathrooms" type="number" inputmode="numeric" min="0" max="20" :label="__('Bathrooms')" :badge="__('Optional')" />
        <flux:input wire:model="sizeSqm" type="number" inputmode="numeric" min="1" :label="__('Size (square metres)')" :badge="__('Optional')" />
        <flux:input wire:model="floor" :label="__('Floor')" :placeholder="__('Ground, 1, 2…')" maxlength="20" :badge="__('Optional')" />
    </div>
</section>

<section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
    <flux:heading size="lg" level="2">{{ __('Rent and status') }}</flux:heading>

    <div class="mt-4 grid gap-5 sm:grid-cols-2">
        <flux:input wire:model="baseRent" inputmode="decimal" :label="__('Rent per year (₦)')" :description="__('The asking rent. Each lease sets its own amount and cycle.')" placeholder="1,500,000" maxlength="20" required data-test="base-rent" />
        <flux:input wire:model="serviceCharge" inputmode="decimal" :label="__('Service charge per year (₦)')" placeholder="150,000" maxlength="20" :badge="__('Optional')" data-test="service-charge" />

        @if ($heldByLease ?? false)
            <div>
                <flux:text class="text-sm font-medium">{{ __('Status') }}</flux:text>
                <flux:badge class="mt-2" :color="$unit->status->color()">{{ __($unit->status->label()) }}</flux:badge>
                <flux:text class="mt-1 text-sm">{{ __('Set by the unit\'s lease.') }}</flux:text>
            </div>
        @else
            <flux:select wire:model="status" :label="__('Status')" :description="__('Occupied and reserved follow leases.')" data-test="unit-status">
                @foreach ($statuses as $option)
                    <option value="{{ $option->value }}">{{ __($option->label()) }}</option>
                @endforeach
            </flux:select>
        @endif

        <div class="sm:col-span-2">
            <flux:textarea wire:model="description" :label="__('Notes')" rows="3" maxlength="2000" :badge="__('Optional')" />
        </div>
    </div>
</section>
