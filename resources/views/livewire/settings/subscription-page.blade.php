{{--
    Settings → Subscription (App\Livewire\Settings\SubscriptionPage, P06-01b;
    BILLING_PAYMENTS.md § 1–2): the plan, its state, today's bill, a
    scheduled change (30 days' notice), what the plan includes and the
    other plans. Cards stack on phones (UX_DESIGN.md § 6).
--}}
<x-page :heading="__('Settings')" :description="__('Your organization\'s details, how tenants pay you, and how your documents look.')">
    <x-workspace.settings-nav current="workspace.settings.subscription" />

    @if ($notice)
        <flux:callout variant="success" icon="check-circle" :heading="$notice" data-test="notice" />
    @endif

    @error('plan')
        <flux:callout variant="danger" icon="x-circle" :heading="$message" data-test="plan-refused" />
    @enderror

    @if ($change)
        <flux:callout variant="warning" icon="calendar-days" :heading="__(':plan changes on :date', ['plan' => $change['plan']->name, 'date' => $change['date']->format('j F Y')])" data-test="scheduled-change">
            <flux:callout.text>
                <ul class="list-disc ps-5">
                    @forelse ($change['lines'] as $line)
                        <li>{{ $line }}</li>
                    @empty
                        <li>{{ __('No change to what you pay or what is included.') }}</li>
                    @endforelse
                </ul>
                <span class="mt-2 block">{{ __('At today\'s :units units your monthly bill goes from :before to :after before VAT, from your first billing period on or after that date.', ['units' => number_format($units), 'before' => $monthly?->format() ?? '—', 'after' => $change['plan']->priceFor($units)->format()]) }}</span>
            </flux:callout.text>
        </flux:callout>
    @endif

    <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="subscription">
        <flux:heading size="lg" level="2">{{ __('Your plan') }}</flux:heading>

        @if ($plan === null)
            <flux:text class="mt-1">{{ __('No plan chosen yet. An owner chooses one while setting up the organization.') }}</flux:text>
        @else
            <dl class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Plan') }}</dt>
                    <dd class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-white" data-test="plan-name">{{ $plan->name }}</dd>
                    @if ($subscription)
                        <dd class="mt-1"><flux:badge size="sm" data-test="status">{{ __($subscription->status->label()) }}</flux:badge></dd>
                    @endif
                </div>
                <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Monthly, at today\'s :units units', ['units' => number_format($units)]) }}</dt>
                    <dd class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-white" data-test="monthly">{{ $monthly?->format() }}</dd>
                    <dd class="text-sm text-zinc-500 dark:text-zinc-400">{{ __(':base base for :included units, :extra each extra unit; :vat% VAT on top', ['base' => $plan->base->format(), 'included' => number_format($plan->includedUnits), 'extra' => $plan->extraUnit?->format() ?? __('custom'), 'vat' => $vat]) }}</dd>
                </div>
                <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800">
                    @if ($subscription?->status === \App\Enums\SubscriptionStatus::Trialing)
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Free trial') }}</dt>
                        <dd class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-white" data-test="trial-left">{{ trans_choice(':count day left|:count days left', $subscription->trialDaysLeft(), ['count' => $subscription->trialDaysLeft()]) }}</dd>
                        <dd class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Ends :date', ['date' => $subscription->trial_ends_at?->timezone('Africa/Lagos')->format('j F Y')]) }}</dd>
                    @else
                        <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Staff users') }}</dt>
                        <dd class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-white" data-test="staff-used">{{ $staffLimit === null ? number_format($staffUsed) : __(':used of :limit', ['used' => number_format($staffUsed), 'limit' => number_format($staffLimit)]) }}</dd>
                    @endif
                </div>
            </dl>

            <flux:heading size="md" level="3" class="mt-6">{{ __('What your plan includes') }}</flux:heading>
            <dl class="mt-2 divide-y divide-zinc-100 text-sm dark:divide-zinc-800" data-test="features">
                @foreach ($features as $feature)
                    <div class="flex items-center justify-between gap-4 py-2" data-feature="{{ $feature['key'] }}">
                        <dt class="text-zinc-600 dark:text-zinc-300">{{ $feature['label'] }}</dt>
                        <dd class="text-end font-medium text-zinc-900 dark:text-white">{{ __($feature['value']) }}@if ($feature['key'] === 'staff_users') <span class="font-normal text-zinc-500">· {{ __(':count in use', ['count' => number_format($staffUsed)]) }}</span>@endif</dd>
                    </div>
                @endforeach
            </dl>
        @endif
    </section>

    @if ($plan !== null && $choices !== [])
        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="plans">
            <flux:heading size="lg" level="2">{{ __('Plans at your :units units', ['units' => number_format($units)]) }}</flux:heading>
            <flux:text class="mt-1">{{ __('A move takes effect at once; your next invoice uses the new plan. Monthly prices before VAT.') }}</flux:text>

            <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
                @foreach ($choices as $choice)
                    <div class="rounded-lg border p-4 {{ $choice->key === $plan->key ? 'border-zinc-900 dark:border-white' : 'border-zinc-200 dark:border-zinc-700' }}" data-plan="{{ $choice->key }}">
                        <div class="font-semibold text-zinc-900 dark:text-white">{{ $choice->name }}</div>
                        <div class="mt-1 text-xl font-semibold text-zinc-900 dark:text-white">{{ $choice->priceFor($units)->format() }}</div>
                        <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ __(':included units included, :extra each extra', ['included' => number_format($choice->includedUnits), 'extra' => $choice->extraUnit?->format() ?? __('custom')]) }}</div>
                        @if ($choice->key === $plan->key)
                            <flux:badge size="sm" class="mt-3">{{ __('Your plan') }}</flux:badge>
                        @elseif ($canChange)
                            <flux:button size="sm" class="mt-3" wire:click="changePlan('{{ $choice->key }}')" wire:confirm="{{ __('Move to :plan now?', ['plan' => $choice->name]) }}" data-test="move-{{ $choice->key }}">{{ __('Move to :plan', ['plan' => $choice->name]) }}</flux:button>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>
    @endif
</x-page>
