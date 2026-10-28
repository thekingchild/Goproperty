{{--
    A handover's page (/o/{organization}/handovers/{handover};
    Modules\Handovers\Livewire\HandoverShow), BILLING_PAYMENTS.md § 7, for
    both organizations. While an offer waits it is the receiving side's
    accept screen (§ 7.3): the bill change is stated before the owner
    accepts. The moving tenants' credit is settled here (P03 decision 11).
    Buttons show only to whoever may act; every action checks the policy
    again.
--}}
@php
    use Modules\Handovers\Enums\CreditSettlement;
    use Modules\Handovers\Enums\HandoverStatus;

    $date = fn ($value) => $value?->timezone('Africa/Lagos')->format('j F Y');
    $status = $handover->shownStatus();
    $landlord = $handover->landlordOrganization;
    $agency = $handover->toOrganization;
    $heading = match (true) {
        $receiving && $handover->isAwaitingAnswer() => __(':landlord offers you its buildings to manage', ['landlord' => $handover->organization->name]),
        $receiving => __('Buildings managed for :landlord', ['landlord' => $handover->organization->name]),
        default => __('Handover to :agency', ['agency' => $agency->name]),
    };
@endphp
<x-page :heading="$heading" :description="__('Offered by :name on :date.', ['name' => $handover->initiatedBy?->name ?? __('a former member'), 'date' => $date($handover->created_at)])">
    <x-slot:actions>
        <flux:button :href="route('workspace.handovers.index', $organization)" wire:navigate icon="arrow-left">{{ __('Handovers') }}</flux:button>
    </x-slot:actions>

    @if ($notice)
        <flux:callout variant="success" icon="check-circle" :heading="$notice" role="status" data-test="handover-notice" />
    @endif
    @error('response')
        <flux:callout variant="danger" icon="x-circle" :heading="$message" role="alert" data-test="response-error" />
    @enderror

    <section class="flex flex-wrap items-center gap-2" aria-label="{{ __('Where it stands') }}">
        <flux:badge :color="$status->color()" data-test="handover-status">{{ __($status->label()) }}</flux:badge>
        <flux:text>
            @switch($status)
                @case(HandoverStatus::Pending)
                    {{ __('Waiting for :agency to answer, until :date.', ['agency' => $agency->name, 'date' => $date($handover->expires_at)]) }}
                    @break
                @case(HandoverStatus::Active)
                    {{ __(':agency has managed the buildings since :date.', ['agency' => $agency->name, 'date' => $date($handover->accepted_at)]) }}
                    @break
                @case(HandoverStatus::Ending)
                    {{ __('Notice given on :given: the buildings return to :landlord on :date.', ['given' => $date($handover->notice_given_at), 'landlord' => $landlord->name, 'date' => $date($handover->ends_at)]) }}
                    @break
                @case(HandoverStatus::Ended)
                    {{ __('The buildings returned to :landlord on :date.', ['landlord' => $landlord->name, 'date' => $date($handover->ended_at)]) }}
                    @break
                @case(HandoverStatus::Declined)
                    {{ __(':agency declined on :date. Nothing moved.', ['agency' => $agency->name, 'date' => $date($handover->responded_at)]) }}
                    @break
                @case(HandoverStatus::Cancelled)
                    {{ __(':landlord withdrew the offer. Nothing moved.', ['landlord' => $handover->organization->name]) }}
                    @break
                @default
                    {{ __('The offer expired on :date. Nothing moved.', ['date' => $date($handover->expires_at)]) }}
            @endswitch
        </flux:text>
    </section>

    @if ($change !== null)
        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" aria-labelledby="bill-heading" data-test="bill-change">
            <flux:heading size="lg" level="2" id="bill-heading" data-test="bill-statement">
                {{ __('Your GoProperty bill goes from :before/month to :after/month (before VAT) from your next billing day, :date.', ['before' => $change->before->amount->format(), 'after' => $change->after->amount->format(), 'date' => $change->from->format('j F Y')]) }}
            </flux:heading>
            <flux:text class="mt-2">
                {{ __('The buildings add :count units to the :units your bill counts today. On the :plan plan: :base a month for up to :included units, plus :extra for each unit over them.', [
                    'count' => $change->units,
                    'units' => $change->before->units,
                    'plan' => $change->after->plan->name,
                    'base' => $change->after->plan->base->format(),
                    'included' => $change->after->plan->includedUnits,
                    'extra' => $change->after->plan->extraUnit?->format() ?? __('a custom price'),
                ]) }}
            </flux:text>
            @if ($change->cheaper !== null)
                <flux:callout class="mt-3" icon="light-bulb" data-test="cheaper-plan" :heading="__('The :plan plan would cost :amount a month for these units: you can change plan in Billing.', ['plan' => $change->cheaper->plan->name, 'amount' => $change->cheaper->amount->format()])" />
            @endif
            <flux:text class="mt-2">{{ __(':vat% VAT is added on each invoice. Units are counted on the billing day; there is no part-month charge.', ['vat' => $change->after->vatPercent]) }}</flux:text>
            <flux:text class="mt-1">{{ __('Accepting moves the buildings, their units, the tenants of their active leases, those leases with their deposits, utility settings and meter readings, and their photos into your account. Bills, receipts and payments :landlord issued stay with it, and it collects what is still owed on them.', ['landlord' => $handover->organization->name]) }}</flux:text>

            @if ($canRespond)
                <div class="mt-4 flex flex-wrap gap-2">
                    <flux:button variant="primary" wire:click="accept" wire:confirm="{{ __('Take over the management of these buildings? Your bill goes to :amount a month.', ['amount' => $change->after->amount->format()]) }}" data-test="accept-button">{{ __('Accept and manage the buildings') }}</flux:button>
                    <flux:button variant="danger" wire:click="decline" wire:confirm="{{ __('Decline :landlord\'s offer?', ['landlord' => $handover->organization->name]) }}" data-test="decline-button">{{ __('Decline') }}</flux:button>
                </div>
            @endif
        </section>
    @endif

    <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" aria-labelledby="buildings-heading">
        <flux:heading size="lg" level="2" id="buildings-heading">{{ __('Buildings') }}</flux:heading>
        <ul class="mt-3 divide-y divide-zinc-100 dark:divide-zinc-800" data-test="handover-buildings">
            @foreach ($handover->buildings as $building)
                <li class="flex flex-col gap-1 py-3 sm:flex-row sm:items-center sm:justify-between" data-building="{{ $building->property_id }}" wire:key="building-{{ $building->id }}">
                    <div class="flex min-w-0 flex-col">
                        <span class="font-medium text-zinc-900 dark:text-white">{{ $building->property_name }}</span>
                        @if ($building->property_address)
                            <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ $building->property_address }}</span>
                        @endif
                    </div>
                    <span class="text-sm text-zinc-600 dark:text-zinc-300">{{ trans_choice('{0} no units|{1} :count unit|[2,*] :count units', $building->unit_count, ['count' => $building->unit_count]) }}</span>
                </li>
            @endforeach
        </ul>
        <dl class="mt-4 grid gap-3 sm:grid-cols-2">
            <div>
                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Who collects rent') }}</dt>
                <dd class="text-zinc-900 dark:text-white" data-test="rent-collection">{{ __($handover->rent_collected_by->label()) }}</dd>
            </div>
            <div>
                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Notice to end it') }}</dt>
                <dd class="text-zinc-900 dark:text-white" data-test="notice-days">{{ trans_choice('{0} No notice: it ends within the hour|{1} :count day|[2,*] :count days', $handover->notice_days, ['count' => $handover->notice_days]) }}</dd>
            </div>
        </dl>

        @if ($canWithdraw || $canGiveNotice || $canWithdrawNotice)
            <div class="mt-4 flex flex-wrap gap-2">
                @if ($canWithdraw)
                    <flux:button variant="danger" wire:click="withdraw" wire:confirm="{{ __('Withdraw the offer to :agency? Its link stops working at once.', ['agency' => $agency->name]) }}" data-test="withdraw-button">{{ __('Withdraw the offer') }}</flux:button>
                @endif
                @if ($canGiveNotice)
                    <flux:button variant="danger" wire:click="giveNotice" wire:confirm="{{ __('End the management? The buildings return to :landlord after :days days.', ['landlord' => $landlord->name, 'days' => $handover->notice_days]) }}" data-test="give-notice-button">{{ $receiving ? __('Resign: give notice') : __('End the management: give notice') }}</flux:button>
                @endif
                @if ($canWithdrawNotice)
                    <flux:button wire:click="withdrawNotice" wire:confirm="{{ __('Withdraw your notice? Management goes on.') }}" data-test="withdraw-notice-button">{{ __('Withdraw my notice') }}</flux:button>
                @endif
            </div>
        @endif
    </section>

    <section class="flex flex-col gap-3" aria-labelledby="tenants-heading">
        <flux:heading size="lg" level="2" id="tenants-heading">{{ __('Tenants and their credit') }}</flux:heading>
        <flux:text>{{ __('Credit is money a tenant paid :landlord ahead. It never moves by itself: :landlord passes it to the agency (the agency records it once the money arrives) or refunds the tenant, both outside GoProperty. Until then it stays on :landlord\'s books. Unpaid bills stay with :landlord, which collects them.', ['landlord' => $landlord->name]) }}</flux:text>

        @if ($handover->tenants->isEmpty())
            <flux:text data-test="no-tenants">{{ __('No tenant holds a lease in these buildings.') }}</flux:text>
        @else
            <ul class="divide-y divide-zinc-100 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-800 dark:border-zinc-700 dark:bg-zinc-900" data-test="handover-tenants">
                @foreach ($handover->tenants as $line)
                    @php($credit = $credits[$line->id] ?? \App\Support\Money::zero())
                    <li class="flex flex-col gap-3 p-4" data-tenant-line="{{ $line->id }}" wire:key="tenant-{{ $line->id }}">
                        <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                            <span class="font-medium text-zinc-900 dark:text-white">{{ $line->tenant_name }}</span>
                            <span class="text-sm text-zinc-600 dark:text-zinc-300">
                                @if ($line->isSettled())
                                    <span data-test="credit-settled">{{ $line->settlement === CreditSettlement::Refund ? __(':amount refunded on :date', ['amount' => $line->settled_kobo?->format(), 'date' => $date($line->settled_at)]) : __(':amount passed to :agency on :date', ['amount' => $line->settled_kobo?->format(), 'agency' => $agency->name, 'date' => $date($line->settled_at)]) }}</span>
                                @else
                                    <span data-test="credit-amount">{{ $credit->isPositive() ? __('Credit: :amount', ['amount' => $credit->format()]) : __('No credit') }}</span>
                                @endif
                                · {{ trans_choice('{0} no unpaid bills|{1} :count unpaid bill (:amount)|[2,*] :count unpaid bills (:amount)', $line->unpaid_bills, ['count' => $line->unpaid_bills, 'amount' => $line->unpaid_kobo->format()]) }}
                            </span>
                        </div>

                        @if (! $line->isSettled() && $credit->isPositive())
                            <div class="flex flex-wrap items-center gap-2">
                                <flux:badge size="sm" color="zinc" data-test="credit-choice">{{ __($line->settlement->label()) }}</flux:badge>
                                @if ($canSettle && $landlordSide)
                                    @foreach (CreditSettlement::cases() as $choice)
                                        @if ($choice !== $line->settlement)
                                            <flux:button size="sm" variant="ghost" wire:click="chooseSettlement({{ $line->id }}, '{{ $choice->value }}')" data-test="choose-{{ $choice->value }}-{{ $line->id }}">{{ __('Change to: :choice', ['choice' => __($choice->label())]) }}</flux:button>
                                        @endif
                                    @endforeach
                                    @if ($line->settlement === CreditSettlement::Refund && $handover->status->wasAccepted())
                                        <flux:button size="sm" variant="primary" wire:click="recordRefund({{ $line->id }})" wire:confirm="{{ __('Record that you refunded :amount to :name? Their credit closes.', ['amount' => $credit->format(), 'name' => $line->tenant_name]) }}" data-test="refund-{{ $line->id }}">{{ __('I refunded :amount', ['amount' => $credit->format()]) }}</flux:button>
                                    @endif
                                @endif
                                @if ($canSettle && $receiving && $line->settlement === CreditSettlement::PassToAgency && in_array($handover->status, [HandoverStatus::Active, HandoverStatus::Ending], true))
                                    <flux:button size="sm" variant="primary" wire:click="confirmArrived({{ $line->id }})" wire:confirm="{{ __('Confirm :amount from :landlord reached your account? It becomes :name\'s opening credit here.', ['amount' => $credit->format(), 'landlord' => $landlord->name, 'name' => $line->tenant_name]) }}" data-test="confirm-{{ $line->id }}">{{ __(':amount arrived', ['amount' => $credit->format()]) }}</flux:button>
                                @endif
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</x-page>
