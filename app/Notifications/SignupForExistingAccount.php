<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Theme\ThemeContext;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Someone signed up with an email address that already has an account. The
 * signup page answers exactly as for a new address (no account
 * enumeration, ONBOARDING_COMPLIANCE.md § 2), and only the owner of the
 * address learns why no new account was made.
 */
final class SignupForExistingAccount extends Notification
{
    use Queueable;

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $brand = app(ThemeContext::class)->current()->name();

        return (new MailMessage)
            ->subject("You already have a {$brand} account")
            ->line("Someone, hopefully you, just tried to create a {$brand} account with this email address. You already have one, so we didn't make another.")
            ->action('Sign in', route('login'))
            ->line('Forgotten your password? You can [reset it]('.route('password.request').').')
            ->line("If this wasn't you, you can ignore this email: nothing has changed on your account.");
    }
}
