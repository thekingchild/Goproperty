<?php

use App\Notifications\Preferences\NotificationPreferences;
use App\Tenancy\BlockedWhileImpersonating;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Settings → Notifications (P05-02): the account's own reminder channels
 * (PLATFORM_CONSOLE.md § 5 "Categories": reminders follow per-user channel
 * preferences). Security and account messages, receipts and in-app notices
 * are always sent. SMS is offered only once the phone number is verified
 * (ARCHITECTURE.md § 6.7: unverified numbers are never texted). The
 * account's own choice: never changed during a GoProperty support session.
 * Below the form, panels other modules add (NotificationSettingsPanels;
 * P05-06: WhatsApp messages from each agency the account rents from).
 */
new #[Title('Notification settings')] class extends Component {
    public bool $remindersByEmail = true;

    public bool $remindersBySms = true;

    public function mount(NotificationPreferences $preferences): void
    {
        $user = Auth::user();
        $this->remindersByEmail = $preferences->allows($user, NotificationPreferences::REMINDERS, 'email');
        $this->remindersBySms = $preferences->allows($user, NotificationPreferences::REMINDERS, 'sms');
    }

    #[BlockedWhileImpersonating]
    public function save(NotificationPreferences $preferences): void
    {
        $preferences->set(Auth::user(), NotificationPreferences::REMINDERS, [
            'email' => $this->remindersByEmail,
            'sms' => $this->remindersBySms,
        ]);

        Flux::toast(variant: 'success', text: __('Notification settings saved.'));
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Notification settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Notifications')" :subheading="__('Choose how we remind you about rent and bills')">
        <form wire:submit="save" class="space-y-6" data-test="notification-settings">
            <flux:switch wire:model="remindersByEmail" :label="__('Reminders by email')" :description="auth()->user()->email !== null ? __('Rent and bill reminders to :email.', ['email' => auth()->user()->email]) : __('Add an email address to your profile to get these.')" />

            @if (auth()->user()->hasVerifiedPhone())
                <flux:switch wire:model="remindersBySms" :label="__('Reminders by SMS')" :description="__('A few short texts to :phone before rent is due and when it is overdue.', ['phone' => auth()->user()->phone])" />
            @else
                <flux:text data-test="sms-unverified">{{ __('Reminders by SMS start once your phone number is verified: open a link your agency sends you by WhatsApp, or ask them to confirm your number in person.') }}</flux:text>
            @endif

            <flux:text class="text-sm">{{ __('Security messages, receipts and notices in the app are always sent.') }}</flux:text>

            <flux:button variant="primary" type="submit" data-test="save-notifications">{{ __('Save') }}</flux:button>
        </form>

        @foreach (app(\App\Notifications\Preferences\NotificationSettingsPanels::class)->all() as $panel)
            <div class="mt-8">
                @livewire($panel, key('notification-panel-'.$panel))
            </div>
        @endforeach
    </x-pages::settings.layout>
</section>
