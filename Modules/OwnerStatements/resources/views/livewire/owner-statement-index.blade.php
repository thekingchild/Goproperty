{{--
    An owner's statements in the agency's workspace
    (Modules\OwnerStatements\Livewire\OwnerStatementIndex, P08-05): the terms,
    the last 12 months and the leases ending in the next 90 days.
--}}
<x-page :heading="__('Statements: :owner', ['owner' => $owner->name])" :description="__('What each month leaves due to the owner, and whether it is settled. GoProperty records it; you pay the owner yourself.')">
    <x-slot:actions>
        <flux:button icon="arrow-left" :href="route('workspace.owners.edit', [$organization, $owner->id])" wire:navigate>{{ __('The owner') }}</flux:button>
        <x-list-export list="owner_statements" />
    </x-slot:actions>

    @if ($notice)
        <flux:callout variant="success" icon="check-circle" :heading="$notice" role="status" data-test="statement-notice" />
    @endif

    <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="statement-terms">
        <flux:heading size="lg" level="2">{{ __('Terms with this owner') }}</flux:heading>
        @if ($canManage)
            <form wire:submit="saveTerms" class="mt-4 flex flex-col gap-4" novalidate>
                <flux:input wire:model="commission" inputmode="decimal" :label="__('Commission (% of rent collected)')" :description="__('Leave blank while it is not agreed: statements then say \'Commission not set\' and take none.')" placeholder="10" maxlength="6" data-test="terms-commission" />
                <flux:checkbox wire:model="utilitiesToOwner" :label="__('Utilities pass to the owner')" :description="__('Utilities collected count toward what the owner is due. Off: they are shown but not counted.')" data-test="terms-utilities" />
                <div><flux:button type="submit" variant="primary" data-test="save-terms">{{ __('Save terms') }}</flux:button></div>
            </form>
        @else
            <dl class="mt-4 grid gap-3 sm:grid-cols-2 text-sm">
                <div><dt class="text-zinc-500 dark:text-zinc-400">{{ __('Commission') }}</dt><dd class="text-zinc-900 dark:text-white">{{ $commission !== '' ? $commission.'%' : __('Not set') }}</dd></div>
                <div><dt class="text-zinc-500 dark:text-zinc-400">{{ __('Utilities pass to the owner') }}</dt><dd class="text-zinc-900 dark:text-white">{{ $utilitiesToOwner ? __('Yes') : __('No') }}</dd></div>
            </dl>
        @endif
    </section>

    <section class="flex flex-col gap-2" data-test="statement-months">
        <flux:heading size="lg" level="2">{{ __('Months') }}</flux:heading>
        <ul class="divide-y divide-zinc-100 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-800 dark:border-zinc-700 dark:bg-zinc-900">
            @foreach ($months as $month)
                <li wire:key="month-{{ $month->month()->key() }}">
                    <a href="{{ route('workspace.owner-statements.show', [$organization, $owner->id, $month->month()->key()]) }}" wire:navigate class="flex min-h-11 flex-wrap items-center justify-between gap-2 p-4 hover:bg-zinc-50 dark:hover:bg-zinc-800" data-test="statement-month" data-month="{{ $month->month()->key() }}" data-status="{{ $month->status() }}" data-net-due="{{ $month->statement->totals->netDue->kobo }}">
                        <span class="font-medium text-zinc-900 dark:text-white">{{ $month->month()->label() }}</span>
                        <span class="flex items-center gap-2">
                            <span class="tabular-nums text-zinc-900 dark:text-white">{{ $month->statement->totals->netDue->format() }}</span>
                            <flux:badge size="sm" :color="$month->statusColor()">{{ __($month->statusLabel()) }}</flux:badge>
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    </section>

    @include('ownerstatements::partials.renewals', ['renewals' => $renewals, 'leaseLinks' => false])
</x-page>
