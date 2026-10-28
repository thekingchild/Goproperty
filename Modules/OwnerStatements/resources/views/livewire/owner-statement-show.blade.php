{{--
    One owner's month in the agency's workspace
    (Modules\OwnerStatements\Livewire\OwnerStatementShow, P08-05): the
    statement, the PDF, and "Mark settled" / "Mark unsettled" for owners and
    administrators.
--}}
@php($settlement = $statement->settlement)
<x-page :heading="__(':owner · :month', ['owner' => $owner->name, 'month' => $statement->month()->label()])" :description="__('Owner statement')">
    <x-slot:actions>
        <flux:button icon="arrow-left" :href="route('workspace.owner-statements.index', [$organization, $owner->id])" wire:navigate>{{ __('All months') }}</flux:button>
        <flux:button icon="arrow-down-tray" :href="route('workspace.owner-statements.pdf', [$organization, $owner->id, $statement->month()->key()])" data-test="statement-pdf">{{ __('Statement (PDF)') }}</flux:button>
    </x-slot:actions>

    @if ($notice)
        <flux:callout variant="success" icon="check-circle" :heading="$notice" role="status" data-test="statement-notice" />
    @endif

    @include('ownerstatements::partials.statement', ['month' => $statement])

    <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="settlement">
        <flux:heading size="lg" level="2">{{ __('Settlement') }}</flux:heading>

        @if ($settlement?->isSettled())
            <flux:text class="mt-2" data-test="settled-by">{{ __('Marked settled on :when by :name.', ['when' => $settlement->settled_at?->timezone('Africa/Lagos')->format('d/m/Y H:i'), 'name' => $people[$settlement->settled_by_user_id] ?? __('someone')]) }}</flux:text>
        @elseif ($settlement?->unsettled_at)
            <flux:text class="mt-2" data-test="unsettled-by">{{ __('Marked unsettled on :when by :name: :reason', ['when' => $settlement->unsettled_at->timezone('Africa/Lagos')->format('d/m/Y H:i'), 'name' => $people[$settlement->unsettled_by_user_id] ?? __('someone'), 'reason' => $settlement->unsettle_reason]) }}</flux:text>
        @endif

        @if ($canManage)
            @if ($settlement?->isSettled())
                <form wire:submit="unsettle" class="mt-4 flex flex-col gap-3" novalidate data-test="unsettle-form">
                    <flux:input wire:model="reason" :label="__('Mark unsettled')" :description="__('Its figures are worked out from the records again until you settle it again. Say why.')" maxlength="500" data-test="unsettle-reason" />
                    <div><flux:button type="submit" wire:confirm="{{ __('Mark this month unsettled?') }}" data-test="unsettle">{{ __('Mark unsettled') }}</flux:button></div>
                </form>
            @elseif ($statement->month()->hasEnded())
                <form wire:submit="settle" class="mt-4 grid gap-4 sm:grid-cols-2" novalidate data-test="settle-form">
                    <flux:input wire:model="paidOn" type="date" :label="__('Date paid to the owner')" data-test="settle-paid-on" />
                    <flux:input wire:model="reference" :label="__('Reference')" :description="__('The transfer reference or cheque number.')" maxlength="100" data-test="settle-reference" />
                    <div class="sm:col-span-2"><flux:button type="submit" variant="primary" data-test="settle">{{ __('Mark settled') }}</flux:button></div>
                </form>
            @else
                <flux:text class="mt-2">{{ __('You can mark this month settled once it has ended.') }}</flux:text>
            @endif
        @endif
    </section>
</x-page>
