{{--
    One month of the owner's statement with one agency
    (Modules\OwnerStatements\Livewire\Portal\PortalStatementMonth, P08-05), in
    that agency's current colours: the statement and its PDF. Read-only.
--}}
<x-page :heading="$statement->month()->label()" :description="__('Statement from :agency', ['agency' => $agency->name])">
    <x-slot:actions>
        <flux:button icon="arrow-left" :href="route('owner.statements.index')" wire:navigate class="min-h-11">{{ __('Statements') }}</flux:button>
        <flux:button icon="arrow-down-tray" :href="route('owner.statements.pdf', [$owner->ownerProfileId, $statement->month()->key()])" class="min-h-11" data-test="statement-pdf">{{ __('PDF') }}</flux:button>
    </x-slot:actions>

    <section class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-900/50" style="border-top: 4px solid {{ $agency->colour }}">
        @include('ownerstatements::partials.agency', ['agency' => $agency])
    </section>

    @include('ownerstatements::partials.statement', ['month' => $statement])
</x-page>
