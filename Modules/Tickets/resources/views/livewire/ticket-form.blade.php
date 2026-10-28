{{--
    Raise a ticket for a tenant or a unit (Modules\Tickets\Livewire\TicketForm).
    The pickers offer only the member's buildings, that building's units,
    the unit's tenants and the members who work there; the server checks
    everything again.
--}}
<x-page :heading="__('Raise a ticket')" :description="__('Report a problem for a tenant, or for a unit with no tenant. The ticket is sent at once and gets its number.')">
    <x-slot:actions>
        <flux:button icon="arrow-left" :href="route('workspace.tickets.index', $organization)" wire:navigate>{{ __('All tickets') }}</flux:button>
    </x-slot:actions>

    @error('ticket')
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="$message" role="alert" data-test="ticket-warning" />
    @enderror

    <form wire:submit="save" class="flex flex-col gap-6" novalidate>
        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="lg" level="2">{{ __('Where') }}</flux:heading>

            <div class="mt-4 grid gap-5 sm:grid-cols-2">
                <flux:select wire:model.live="propertyId" :label="__('Building')" required data-test="ticket-building">
                    <option value="">{{ __('Choose a building') }}</option>
                    @foreach ($buildings as $optionId => $optionName)
                        <option value="{{ $optionId }}">{{ $optionName }}</option>
                    @endforeach
                </flux:select>

                <flux:select wire:model.live="unitId" :label="__('Unit')" required data-test="ticket-unit">
                    <option value="">{{ __('Choose a unit') }}</option>
                    @foreach ($units as $option)
                        <option value="{{ $option->id }}">{{ $option->label }}</option>
                    @endforeach
                </flux:select>

                <div class="sm:col-span-2">
                    <flux:select wire:model="tenantId" :label="__('Tenant')" data-test="ticket-tenant">
                        <option value="">{{ __('No tenant (an empty unit or a common area)') }}</option>
                        @foreach ($tenants as $optionId => $optionName)
                            <option value="{{ $optionId }}">{{ $optionName }}</option>
                        @endforeach
                    </flux:select>
                    <flux:text class="mt-1 text-sm">{{ __('The tenant sees the ticket in their portal and can confirm when it is fixed.') }}</flux:text>
                </div>
            </div>
        </section>

        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="lg" level="2">{{ __('The problem') }}</flux:heading>

            <div class="mt-4 grid gap-5 sm:grid-cols-2">
                <flux:select wire:model="category" :label="__('Kind of problem')" required data-test="ticket-category">
                    <option value="">{{ __('Choose one') }}</option>
                    @foreach ($categories as $option)
                        <option value="{{ $option->value }}">{{ __($option->label()) }}</option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="priority" :label="__('Priority')" required data-test="ticket-priority">
                    @foreach ($priorities as $option)
                        <option value="{{ $option->value }}">{{ __($option->label()) }}: {{ __($option->hint()) }}</option>
                    @endforeach
                </flux:select>

                <div class="sm:col-span-2">
                    <flux:input wire:model="title" :label="__('Title')" :placeholder="__('Kitchen sink leaking')" :maxlength="$titleMax" required data-test="ticket-title" />
                </div>

                <div class="sm:col-span-2">
                    <flux:textarea wire:model="description" :label="__('Description')" rows="5" :maxlength="$descriptionMax" required data-test="ticket-description" />
                </div>

                <flux:select wire:model="assigneeId" :label="__('Assign to')" data-test="ticket-assignee">
                    <option value="">{{ __('Nobody yet') }}</option>
                    @foreach ($assignees as $optionId => $optionName)
                        <option value="{{ $optionId }}">{{ $optionName }}</option>
                    @endforeach
                </flux:select>
            </div>
        </section>

        <div class="flex flex-wrap gap-3">
            <flux:button type="submit" variant="primary" data-test="save-ticket">{{ __('Raise the ticket') }}</flux:button>
        </div>
    </form>
</x-page>
