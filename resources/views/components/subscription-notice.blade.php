{{--
    Shown on every workspace page to those who see Settings → Subscription
    (owners, administrators; P06-01b, BILLING_PAYMENTS.md § 1.3 and § 2):
    trial days left, a scheduled change of the plan's terms (the 30 days'
    notice), and an overdue subscription. Suspension has its own notice.
--}}
@php
    $context = app(\App\Tenancy\OrganizationContext::class);
    $user = auth()->user();
    $show = $context->has() && $user instanceof \App\Models\User && $user->can('viewAny', \App\Models\Subscription::class);
    $overview = $show ? app(\App\Subscriptions\SubscriptionOverview::class) : null;
    $subscription = $overview?->subscription();
    $change = $overview?->scheduledChange();
    $url = $show && Route::has('workspace.settings.subscription') ? route('workspace.settings.subscription', $context->get()) : null;
    $status = $subscription?->status;
@endphp
@if ($subscription !== null)
    @if ($status === \App\Enums\SubscriptionStatus::Trialing)
        <flux:callout icon="sparkles" class="mb-6" role="status" data-test="trial-notice"
            :heading="trans_choice('Free trial: :count day left|Free trial: :count days left', $subscription->trialDaysLeft(), ['count' => $subscription->trialDaysLeft()])">
            <flux:callout.text>{{ __('Your :plan plan starts on :date.', ['plan' => $subscription->plan->name, 'date' => $subscription->trial_ends_at?->timezone('Africa/Lagos')->format('j F Y')]) }} @if ($url)<a href="{{ $url }}" class="font-medium underline">{{ __('See your subscription') }}</a>@endif</flux:callout.text>
        </flux:callout>
    @elseif (in_array($status, [\App\Enums\SubscriptionStatus::PastDue, \App\Enums\SubscriptionStatus::Grace], true))
        <flux:callout variant="danger" icon="exclamation-triangle" class="mb-6" role="status" data-test="overdue-notice"
            :heading="$status === \App\Enums\SubscriptionStatus::Grace ? __('Final days before suspension: your subscription is unpaid') : __('Your subscription payment is overdue')">
            <flux:callout.text>{{ __('Pay the open invoice to keep everything working. Nothing is deleted.') }} @if ($url)<a href="{{ $url }}" class="font-medium underline">{{ __('See your subscription') }}</a>@endif</flux:callout.text>
        </flux:callout>
    @endif

    @if ($change !== null)
        <flux:callout variant="warning" icon="calendar-days" class="mb-6" role="status" data-test="plan-change-notice"
            :heading="__('Your :plan plan changes on :date', ['plan' => $change['plan']->name, 'date' => $change['date']->format('j F Y')])">
            <flux:callout.text>{{ implode('; ', $change['lines']) }}. @if ($url)<a href="{{ $url }}" class="font-medium underline">{{ __('What it means for you') }}</a>@endif</flux:callout.text>
        </flux:callout>
    @endif
@endif
