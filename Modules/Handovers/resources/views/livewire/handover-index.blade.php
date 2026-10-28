{{--
    Handovers (/o/{organization}/handovers; Modules\Handovers\Livewire\
    HandoverIndex), BILLING_PAYMENTS.md § 7. A landlord's organization
    offers chosen buildings to an agency; every organization sees the
    handovers it offered and those offered to it. Owners only; every action
    checks its policy again.
--}}
@php
    $date = fn ($value) => $value?->timezone('Africa/Lagos')->format('j M Y');
    $units = fn (\Modules\Handovers\Models\ManagementHandover $handover): string => trans_choice('{1} :count building|[2,*] :count buildings', $handover->buildings->count(), ['count' => $handover->buildings->count()]).' · '.trans_choice('{0} no units|{1} :count unit|[2,*] :count units', $handover->unitCount(), ['count' => $handover->unitCount()]);
@endphp
<x-page :heading="__('Handovers')" :description="__('Hand buildings to an agency to manage, take over buildings offered to you, and end a management with notice.')">
    @if ($notice)
        <flux:callout variant="success" icon="check-circle" :heading="$notice" role="status" data-test="handovers-notice" />
    @endif

    <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" aria-labelledby="offer-heading">
        <flux:heading size="lg" level="2" id="offer-heading">{{ __('Hand buildings to an agency') }}</flux:heading>

        @if (! $canOffer)
            <flux:text class="mt-1" data-test="offer-unavailable">{{ __('Landlords on GoProperty hand their buildings to you from their own account; their offers appear below. Handing buildings over is for a landlord\'s organization.') }}</flux:text>
        @elseif ($buildingOptions === [])
            <flux:text class="mt-1" data-test="no-offerable-buildings">{{ __('You have no active building that is not already in a handover.') }}</flux:text>
        @else
            <flux:text class="mt-1">{{ __('The agency must already be on GoProperty. Its owners are emailed and have :days days to accept; nothing moves unless they do. Once accepted, the buildings move to the agency\'s account with their units, tenants, active leases and files, and you follow them in the owner portal. Bills, receipts and payments you issued stay with you.', ['days' => $validDays]) }}</flux:text>

            <form wire:submit="offer" class="mt-4 flex flex-col gap-5" novalidate>
                <flux:checkbox.group wire:model="buildings" :label="__('Buildings')" data-test="offer-buildings">
                    @foreach ($buildingOptions as $buildingId => $name)
                        <flux:checkbox :value="(string) $buildingId" :label="$name" data-test="offer-building-{{ $buildingId }}" />
                    @endforeach
                </flux:checkbox.group>
                @error('buildings')<flux:callout variant="danger" icon="x-circle" :heading="$message" role="alert" data-test="offer-error" />@enderror

                <flux:input wire:model="target" :label="__('The agency\'s web address or an owner\'s email')" placeholder="goproperty.ng/o/their-agency" autocomplete="off" maxlength="255" required data-test="offer-target" />

                <flux:radio.group wire:model="rentCollection" :label="__('Who collects rent')" data-test="offer-rent">
                    <flux:radio value="agency" :label="__('The agency collects rent')" :description="__('Tenants pay the agency\'s own account; the agency pays you after its commission, outside GoProperty.')" />
                    <flux:radio value="owner" :label="__('I collect rent')" :description="__('Not available yet: it needs a collection account per building.')" disabled />
                </flux:radio.group>

                <flux:input wire:model="noticeDays" type="number" min="0" max="{{ $maxNotice }}" inputmode="numeric" :label="__('Notice period (days)')" :description="__('Either side may end the management with this much notice (0 to :max days); the buildings then come back to you.', ['max' => $maxNotice])" class="sm:max-w-xs" data-test="offer-notice" />

                <div>
                    <flux:button type="submit" variant="primary" data-test="offer-button" wire:confirm="{{ __('Offer these buildings to this agency? Its owners will be emailed.') }}">{{ __('Offer the buildings') }}</flux:button>
                </div>
            </form>
        @endif
    </section>

    <section aria-labelledby="outgoing-heading" class="flex flex-col gap-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <flux:heading size="lg" level="2" id="outgoing-heading">{{ __('Handovers you offered') }}</flux:heading>
            @if ($outgoing->isNotEmpty())
                <x-list-export list="handovers_made" size="sm" />
            @endif
        </div>
        @error('outgoing')<flux:callout variant="danger" icon="x-circle" :heading="$message" role="alert" />@enderror

        @if ($outgoing->isEmpty())
            <flux:text>{{ __('You have not offered any building.') }}</flux:text>
        @else
            <ul class="divide-y divide-zinc-100 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-800 dark:border-zinc-700 dark:bg-zinc-900" data-test="outgoing-list">
                @foreach ($outgoing as $handover)
                    <li class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between" wire:key="outgoing-{{ $handover->id }}" data-handover="{{ $handover->id }}">
                        <div class="flex min-w-0 flex-col">
                            <span class="truncate font-medium text-zinc-900 dark:text-white">{{ $handover->toOrganization->name }}</span>
                            <span class="text-sm text-zinc-500 dark:text-zinc-400">
                                {{ $units($handover) }} · {{ __('offered :date', ['date' => $date($handover->created_at)]) }}
                                @if ($handover->isAwaitingAnswer())
                                    · {{ __('expires :date', ['date' => $date($handover->expires_at)]) }}
                                @elseif ($handover->ends_at)
                                    · {{ __('ends :date', ['date' => $date($handover->ends_at)]) }}
                                @endif
                            </span>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <flux:badge size="sm" :color="$handover->shownStatus()->color()">{{ __($handover->shownStatus()->label()) }}</flux:badge>
                            <flux:button size="sm" variant="ghost" :href="route('workspace.handovers.show', [$organization, $handover->id])" wire:navigate data-test="open-{{ $handover->id }}">{{ __('Details') }}</flux:button>
                            @if ($handover->isAwaitingAnswer())
                                <flux:button size="sm" variant="danger" wire:click="withdraw({{ $handover->id }})" wire:confirm="{{ __('Withdraw the offer to :name? Its link stops working at once.', ['name' => $handover->toOrganization->name]) }}" data-test="withdraw-{{ $handover->id }}">{{ __('Withdraw') }}</flux:button>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section aria-labelledby="incoming-heading" class="flex flex-col gap-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <flux:heading size="lg" level="2" id="incoming-heading">{{ __('Handovers offered to you') }}</flux:heading>
            @if ($incoming->isNotEmpty())
                <x-list-export list="handovers_received" size="sm" />
            @endif
        </div>

        @if ($incoming->isEmpty())
            <flux:text>{{ __('No landlord has offered you buildings to manage.') }}</flux:text>
        @else
            <ul class="divide-y divide-zinc-100 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-800 dark:border-zinc-700 dark:bg-zinc-900" data-test="incoming-list">
                @foreach ($incoming as $handover)
                    <li class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between" wire:key="incoming-{{ $handover->id }}" data-handover="{{ $handover->id }}">
                        <div class="flex min-w-0 flex-col">
                            <span class="truncate font-medium text-zinc-900 dark:text-white">{{ $handover->organization->name }}</span>
                            <span class="text-sm text-zinc-500 dark:text-zinc-400">
                                {{ $units($handover) }} · {{ __('offered :date', ['date' => $date($handover->created_at)]) }}
                                @if ($handover->isAwaitingAnswer())
                                    · {{ __('expires :date', ['date' => $date($handover->expires_at)]) }}
                                @elseif ($handover->ends_at)
                                    · {{ __('ends :date', ['date' => $date($handover->ends_at)]) }}
                                @endif
                            </span>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <flux:badge size="sm" :color="$handover->shownStatus()->color()">{{ __($handover->shownStatus()->label()) }}</flux:badge>
                            <flux:button size="sm" :variant="$handover->isAwaitingAnswer() ? 'primary' : 'ghost'" :href="route('workspace.handovers.show', [$organization, $handover->id])" wire:navigate data-test="review-{{ $handover->id }}">
                                {{ $handover->isAwaitingAnswer() ? __('Review') : __('Details') }}
                            </flux:button>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</x-page>
