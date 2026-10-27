{{--
    Add or edit a building (Modules\Properties\Livewire\PropertyForm).
    Photos are added on the building's page once it is saved.
--}}
<x-page :heading="$property ? __('Edit :name', ['name' => $property->name]) : __('Add building')" :description="$property ? __('Details of a building :organization manages.', ['organization' => $organization->name]) : __('A building you manage. You add its flats, shops and offices after saving it.')">
    <x-slot:actions>
        @if ($property)
            <flux:button icon="arrow-left" :href="route('workspace.buildings.show', [$organization, $property])" wire:navigate>{{ __('Back to building') }}</flux:button>
        @else
            <flux:button icon="arrow-left" :href="route('workspace.buildings.index', $organization)" wire:navigate>{{ __('All buildings') }}</flux:button>
        @endif
    </x-slot:actions>

    <form wire:submit="save" class="flex flex-col gap-6" novalidate>
        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="lg" level="2">{{ __('The building') }}</flux:heading>

            <div class="mt-4 grid gap-5 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <flux:input wire:model="name" :label="__('Building name')" placeholder="Palm Court" maxlength="160" autocomplete="off" required data-test="name" />
                </div>

                <flux:select wire:model="propertyType" :label="__('Building type')">
                    @foreach ($types as $option)
                        <option value="{{ $option->value }}">{{ __($option->label()) }}</option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="yearBuilt" type="number" inputmode="numeric" :label="__('Year built')" min="1800" max="{{ now()->year + 5 }}" :badge="__('Optional')" />

                <div class="sm:col-span-2">
                    <flux:select wire:model="ownerProfileId" :label="__('Owner')" :description="__('The person or company who owns the building. Leave it empty when :organization owns it.', ['organization' => $organization->name])" data-test="owner">
                        <option value="">{{ __('No owner on file') }}</option>
                        @foreach ($owners as $ownerOption)
                            <option value="{{ $ownerOption->id }}">{{ $ownerOption->display_name }}{{ $ownerOption->deleted_at ? ' ('.__('archived').')' : '' }}</option>
                        @endforeach
                    </flux:select>
                    @if ($canAddOwner)
                        <flux:text class="mt-1 text-sm">
                            <flux:link :href="route('workspace.owners.create', $organization)" wire:navigate>{{ __('Add a new owner first') }}</flux:link>
                        </flux:text>
                    @endif
                </div>
            </div>
        </section>

        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="lg" level="2">{{ __('Where it is') }}</flux:heading>

            <div class="mt-4 grid gap-5 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <flux:textarea wire:model="address" :label="__('Address')" rows="2" maxlength="300" required />
                </div>

                <flux:select wire:model="state" :label="__('State')" required>
                    <option value="">{{ __('Choose a state') }}</option>
                    @foreach ($states as $stateName)
                        <option value="{{ $stateName }}">{{ $stateName }}</option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="lga" :label="__('LGA')" maxlength="64" />

                <div class="sm:col-span-2">
                    <flux:input wire:model="landmark" :label="__('Nearest landmark')" :description="__('Helps tenants and artisans find it, such as “Opposite Mega Plaza”.')" maxlength="160" />
                </div>
            </div>
        </section>

        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="lg" level="2">{{ __('Amenities') }}</flux:heading>

            <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3" data-test="amenities">
                @foreach ($suggestedAmenities as $amenity)
                    <flux:checkbox wire:model="amenities" :value="$amenity" :label="__($amenity)" />
                @endforeach
            </div>

            <div class="mt-4">
                <flux:input wire:model="otherAmenities" :label="__('Other amenities')" :description="__('Separate them with commas.')" maxlength="600" />
            </div>
            <flux:error name="amenities" />
            <flux:error name="amenities.*" />
        </section>

        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:textarea wire:model="description" :label="__('Description')" :description="__('Anything your team should know about the building.')" rows="3" maxlength="2000" />
        </section>

        <div class="flex gap-2">
            <flux:button type="submit" variant="primary" data-test="save-building">{{ $property ? __('Save building') : __('Add building') }}</flux:button>
            <flux:button :href="$property ? route('workspace.buildings.show', [$organization, $property]) : route('workspace.buildings.index', $organization)" wire:navigate>{{ __('Cancel') }}</flux:button>
        </div>
    </form>
</x-page>
