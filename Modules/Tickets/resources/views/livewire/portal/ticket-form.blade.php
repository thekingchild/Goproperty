{{--
    Report a problem, or change a draft
    (Modules\Tickets\Livewire\Portal\PortalTicketForm, P04-01). Phone
    first: one column, 44 px targets, no autofocus. The server checks the
    home and everything else again.
--}}
<x-page :heading="$editing ? __('Draft problem report') : __('Report a problem')" :description="__('Tell the agency what is wrong. They see it as soon as you send it.')">
    <x-slot:actions>
        <flux:button icon="arrow-left" :href="route('portal.tickets.index')" wire:navigate class="min-h-11" data-test="portal-ticket-back">{{ __('Repairs') }}</flux:button>
    </x-slot:actions>

    <x-portal.nav />

    @error('ticket')
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="$message" role="alert" data-test="portal-ticket-warning" />
    @enderror

    @if ($homes === [])
        <x-empty-state icon="home-modern" :heading="__('No home to report for')" :description="__('You can report a problem for a home you rent. If your agency\'s account is on hold, call them instead.')" />
    @else
        <form wire:submit="send" class="flex flex-col gap-5" novalidate data-test="portal-ticket-form">
            <section class="flex flex-col gap-4 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:select wire:model="home" :label="__('Which home')" required class="min-h-11" data-test="ticket-home">
                    @if (count($homes) > 1)
                        <option value="">{{ __('Choose your home') }}</option>
                    @endif
                    @foreach ($homes as $option)
                        <option value="{{ $option->key }}">{{ $option->label() }} ({{ $option->agency }})</option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="category" :label="__('What kind of problem')" required class="min-h-11" data-test="ticket-category">
                    <option value="">{{ __('Choose one') }}</option>
                    @foreach ($categories as $option)
                        <option value="{{ $option->value }}">{{ __($option->label()) }}</option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="priority" :label="__('How urgent is it')" required class="min-h-11" data-test="ticket-priority">
                    @foreach ($priorities as $option)
                        <option value="{{ $option->value }}">{{ __($option->label()) }}: {{ __($option->hint()) }}</option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="title" :label="__('In a few words')" :placeholder="__('Kitchen sink leaking')" :maxlength="$titleMax" required class:input="min-h-11" data-test="ticket-title" />

                <flux:textarea wire:model="description" :label="__('What is wrong')" :description="__('Where it is, what happens and since when.')" rows="5" :maxlength="$descriptionMax" required data-test="ticket-description" />
            </section>

            <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                <flux:button type="submit" variant="primary" class="min-h-11" data-test="send-ticket">{{ __('Send to the agency') }}</flux:button>
                <flux:button type="button" wire:click="saveDraft" class="min-h-11" data-test="save-draft">{{ __('Save as draft') }}</flux:button>
                @if ($editing)
                    <flux:button type="button" wire:click="discard" wire:confirm="{{ __('Discard this draft? It was never sent.') }}" variant="ghost" class="min-h-11" data-test="discard-draft">{{ __('Discard draft') }}</flux:button>
                @endif
            </div>
        </form>
    @endif
</x-page>
